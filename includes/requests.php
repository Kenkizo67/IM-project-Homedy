<?php
declare(strict_types=1);

/*
 * Requests with one or more workers.
 *
 * A worker's place on a request lives in request_workers.status:
 *   invited  -> the customer picked the worker; the worker answers (selected / declined)
 *   applied  -> the worker accepted an open request; the customer answers (selected / rejected)
 *   selected -> working on it
 *   done     -> finished their part
 *   rejected / declined -> not on the job
 *
 * The request is "pending" until the first worker is selected, "accepted" while it is being worked on,
 * and "completed" when every selected worker is done and no more workers are wanted.
 * The price is PER WORKER; the payment is price x number of workers who did the job.
 *
 * The functions below that change data use a transaction and lock the request row (FOR UPDATE),
 * so two people clicking at the same moment cannot fill more places than the customer asked for.
 */

const MAX_WORKERS_PER_REQUEST = 10;

/** How many places are already taken (selected or done). */
function slots_taken(PDO $pdo, int $requestId): int
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM request_workers WHERE request_id = ? AND status IN ('selected', 'done')");
    $s->execute([$requestId]);
    return (int) $s->fetchColumn();
}

/** Locks and returns the request row, or null. Call inside a transaction. */
function lock_request(PDO $pdo, int $requestId): ?array
{
    $s = $pdo->prepare('SELECT request_id, customer_id, category_id, price, workers_needed, open_to_all, status
                        FROM job_requests WHERE request_id = ? FOR UPDATE');
    $s->execute([$requestId]);
    return $s->fetch() ?: null;
}

/** Everyone still waiting (invited / applied) is turned down. */
function reject_waiting(PDO $pdo, int $requestId): void
{
    $pdo->prepare("UPDATE request_workers SET status = 'rejected', date_response = ?
                   WHERE request_id = ? AND status IN ('invited', 'applied')")
        ->execute([now_sql(), $requestId]);
}

/**
 * Puts a worker on the job (from 'invited' or 'applied').
 * @return string ok | full (no places left) | gone (not possible any more)
 */
function select_worker(PDO $pdo, int $requestId, int $workerId, string $from, ?int $customerId = null): string
{
    $pdo->beginTransaction();
    try {
        $req = lock_request($pdo, $requestId);
        if (!$req || !in_array($req['status'], ['pending', 'accepted'], true)
            || ($customerId !== null && (int) $req['customer_id'] !== $customerId)) {
            $pdo->rollBack();
            return 'gone';
        }
        $taken = slots_taken($pdo, $requestId);
        if ($taken >= (int) $req['workers_needed']) {
            $pdo->rollBack();
            return 'full';
        }
        $u = $pdo->prepare("UPDATE request_workers SET status = 'selected', date_response = ?
                            WHERE request_id = ? AND worker_id = ? AND status = ?");
        $u->execute([now_sql(), $requestId, $workerId, $from]);
        if ($u->rowCount() !== 1) {
            $pdo->rollBack();
            return 'gone';
        }
        if ($req['status'] === 'pending') {
            $pdo->prepare("UPDATE job_requests SET status = 'accepted', date_accepted = ? WHERE request_id = ? AND status = 'pending'")
                ->execute([now_sql(), $requestId]);
        }
        if ($taken + 1 >= (int) $req['workers_needed']) {
            reject_waiting($pdo, $requestId);   // all places filled
        }
        $pdo->commit();
        if ($from === 'invited') {
            notify_worker_accepted($pdo, $requestId, $workerId, false);   // a worker the customer picked said yes
        }
        return 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** The customer says no to a worker who applied. */
function reject_applicant(PDO $pdo, int $requestId, int $workerId, int $customerId): bool
{
    $s = $pdo->prepare("UPDATE request_workers SET status = 'rejected', date_response = ?
                        WHERE request_id = ? AND worker_id = ? AND status = 'applied'
                          AND request_id IN (SELECT request_id FROM job_requests WHERE request_id = ? AND customer_id = ?)");
    $s->execute([now_sql(), $requestId, $workerId, $requestId, $customerId]);
    return $s->rowCount() === 1;
}

/** An invited worker says no. */
function decline_invitation(PDO $pdo, int $requestId, int $workerId): bool
{
    $s = $pdo->prepare("UPDATE request_workers SET status = 'declined', date_response = ?
                        WHERE request_id = ? AND worker_id = ? AND status = 'invited'");
    $s->execute([now_sql(), $requestId, $workerId]);
    return $s->rowCount() === 1;
}

/**
 * A worker accepts an OPEN request. They then wait for the customer to accept or reject them.
 * @return string ok | exists | full | gone
 */
function apply_to_request(PDO $pdo, int $requestId, int $workerId): string
{
    $pdo->beginTransaction();
    try {
        $req = lock_request($pdo, $requestId);
        if (!$req || !in_array($req['status'], ['pending', 'accepted'], true) || !(int) $req['open_to_all']) {
            $pdo->rollBack();
            return 'gone';
        }
        $skill = $pdo->prepare('SELECT COUNT(*) FROM worker_skills WHERE worker_id = ? AND category_id = ?');
        $skill->execute([$workerId, (int) $req['category_id']]);
        $ver = $pdo->prepare('SELECT is_verified FROM workers WHERE worker_id = ?');
        $ver->execute([$workerId]);
        if ((int) $skill->fetchColumn() === 0 || !(int) $ver->fetchColumn()) {
            $pdo->rollBack();
            return 'gone';
        }
        if (slots_taken($pdo, $requestId) >= (int) $req['workers_needed']) {
            $pdo->rollBack();
            return 'full';
        }
        $has = $pdo->prepare('SELECT COUNT(*) FROM request_workers WHERE request_id = ? AND worker_id = ?');
        $has->execute([$requestId, $workerId]);
        if ((int) $has->fetchColumn() > 0) {
            $pdo->rollBack();
            return 'exists';
        }
        $pdo->prepare("INSERT INTO request_workers (request_id, worker_id, status, date_added) VALUES (?, ?, 'applied', ?)")
            ->execute([$requestId, $workerId, now_sql()]);
        $pdo->commit();
        notify_worker_accepted($pdo, $requestId, $workerId, true);    // customer: "X accepted your request"
        return 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Completes the request when nobody is still working and all wanted places are done.
 * Call inside a transaction that holds the request lock. Creates the payment (price x workers).
 */
function complete_if_finished(PDO $pdo, array $req): bool
{
    if ($req['status'] !== 'accepted') {
        return false;
    }
    $c = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN status = 'selected' THEN 1 ELSE 0 END), 0) AS working,
            COALESCE(SUM(CASE WHEN status = 'done'     THEN 1 ELSE 0 END), 0) AS finished
        FROM request_workers WHERE request_id = ?");
    $c->execute([(int) $req['request_id']]);
    $n = $c->fetch();
    if ((int) $n['working'] > 0 || (int) $n['finished'] < (int) $req['workers_needed']) {
        return false;
    }
    $pdo->prepare("UPDATE job_requests SET status = 'completed', date_completed = ? WHERE request_id = ? AND status = 'accepted'")
        ->execute([now_sql(), (int) $req['request_id']]);
    $pdo->prepare(
        "INSERT INTO payments (request_id, amount, payment_status)
         SELECT ?, ?, 'unpaid' WHERE NOT EXISTS (SELECT 1 FROM payments WHERE request_id = ?)"
    )->execute([(int) $req['request_id'], (float) $req['price'] * (int) $n['finished'], (int) $req['request_id']]);
    return true;
}

/**
 * A selected worker marks their part finished.
 * @return string ok | completed (that was the last one: the request is now completed) | gone
 */
function mark_worker_done(PDO $pdo, int $requestId, int $workerId): string
{
    $pdo->beginTransaction();
    try {
        $req = lock_request($pdo, $requestId);
        if (!$req || $req['status'] !== 'accepted' || (float) $req['price'] <= 0) {
            $pdo->rollBack();
            return 'gone';
        }
        $u = $pdo->prepare("UPDATE request_workers SET status = 'done', date_done = ?
                            WHERE request_id = ? AND worker_id = ? AND status = 'selected'");
        $u->execute([now_sql(), $requestId, $workerId]);
        if ($u->rowCount() !== 1) {
            $pdo->rollBack();
            return 'gone';
        }
        $finished = complete_if_finished($pdo, $req);
        $pdo->commit();
        if ($finished) {
            notify_job_done($pdo, $requestId);
        }
        return $finished ? 'completed' : 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * The customer stops looking for more workers and goes ahead with the ones already accepted.
 * @return string ok | completed | gone
 */
function close_recruiting(PDO $pdo, int $requestId, int $customerId): string
{
    $pdo->beginTransaction();
    try {
        $req = lock_request($pdo, $requestId);
        if (!$req || (int) $req['customer_id'] !== $customerId || $req['status'] !== 'accepted') {
            $pdo->rollBack();
            return 'gone';
        }
        $taken = slots_taken($pdo, $requestId);
        if ($taken < 1) {
            $pdo->rollBack();
            return 'gone';
        }
        $pdo->prepare('UPDATE job_requests SET workers_needed = ? WHERE request_id = ?')->execute([$taken, $requestId]);
        $req['workers_needed'] = $taken;
        reject_waiting($pdo, $requestId);
        $finished = complete_if_finished($pdo, $req);
        $pdo->commit();
        if ($finished) {
            notify_job_done($pdo, $requestId);
        }
        return $finished ? 'completed' : 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Workers on each request, with their rating and (if any) the customer's review of them for that request.
 * @param int[] $requestIds
 * @return array<int, list<array>> request_id => rows
 */
function load_request_workers(PDO $pdo, array $requestIds): array
{
    $requestIds = array_values(array_unique(array_map('intval', $requestIds)));
    if (!$requestIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($requestIds), '?'));
    $s = $pdo->prepare(
        "SELECT rw.id, rw.request_id, rw.worker_id, rw.status, rw.date_response, rw.date_done,
                u.first_name, u.last_name, w.contact_number, w.address,
                (SELECT AVG(x.rating)  FROM reviews x WHERE x.worker_id = rw.worker_id) AS avg_rating,
                (SELECT COUNT(*)       FROM reviews x WHERE x.worker_id = rw.worker_id) AS review_count,
                rv.rating, rv.quality_rating, rv.punctuality_rating, rv.professionalism_rating, rv.efficiency_rating, rv.remarks
         FROM request_workers rw
         JOIN workers w ON w.worker_id = rw.worker_id
         JOIN users u ON u.user_id = w.user_id
         LEFT JOIN reviews rv ON rv.request_id = rw.request_id AND rv.worker_id = rw.worker_id
         WHERE rw.request_id IN ($in)
         ORDER BY rw.id"
    );
    $s->execute($requestIds);
    $out = [];
    foreach ($s->fetchAll() as $row) {
        $out[(int) $row['request_id']][] = $row;
    }
    return $out;
}

/** "Ana Cruz, Ben Reyes" for the workers on the job (selected or done by default). */
function worker_names(array $rows, array $statuses = ['selected', 'done']): string
{
    $names = [];
    foreach ($rows as $r) {
        if (in_array($r['status'], $statuses, true)) {
            $names[] = $r['first_name'] . ' ' . $r['last_name'];
        }
    }
    return implode(', ', $names);
}
