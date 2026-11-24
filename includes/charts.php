<?php
declare(strict_types=1);

/**
 * A pie chart drawn as inline SVG (no libraries, works offline), with a legend that repeats every
 * number so nothing depends on colour alone.
 *
 * @param string $title   heading of the card
 * @param array  $slices  list of ['label' => string, 'value' => int, 'color' => '#rrggbb', 'text' => '#rrggbb' (label colour on the slice), 'href' => optional link]
 */
function pie_card(string $title, array $slices): string
{
    $total = 0;
    foreach ($slices as $s) {
        $total += max(0, (int) $s['value']);
    }

    $summary = [];
    foreach ($slices as $s) {
        $summary[] = $s['label'] . ': ' . (int) $s['value'];
    }

    $cx = 100.0;
    $cy = 100.0;
    $r  = 94.0;
    $svg = '<svg class="pie" viewBox="0 0 200 200" role="img" aria-label="' . e($title . '. ' . implode(', ', $summary)) . '">';

    if ($total <= 0) {
        $svg .= '<circle cx="100" cy="100" r="94" fill="#ece7dc"/>'
              . '<text x="100" y="105" text-anchor="middle" font-size="14" fill="#5e6b66">No data yet</text>';
    } else {
        $nonZero = array_values(array_filter($slices, fn($s) => (int) $s['value'] > 0));
        if (count($nonZero) === 1) {
            // A single slice is a full circle (an arc cannot start and end on the same point).
            $s = $nonZero[0];
            $svg .= '<circle cx="100" cy="100" r="94" fill="' . e($s['color']) . '"><title>' . e($s['label'] . ': ' . $s['value'] . ' (100%)') . '</title></circle>'
                  . '<text x="100" y="106" text-anchor="middle" font-size="18" font-weight="700" fill="' . e($s['text'] ?? '#fff') . '">100%</text>';
        } else {
            $angle = -M_PI / 2;   // start at 12 o'clock
            foreach ($nonZero as $s) {
                $share = (int) $s['value'] / $total;
                $sweep = $share * 2 * M_PI;
                $end   = $angle + $sweep;
                $x1 = $cx + $r * cos($angle);
                $y1 = $cy + $r * sin($angle);
                $x2 = $cx + $r * cos($end);
                $y2 = $cy + $r * sin($end);
                $large = $sweep > M_PI ? 1 : 0;
                $svg .= sprintf(
                    '<path d="M%.2f %.2f L%.2f %.2f A%.2f %.2f 0 %d 1 %.2f %.2f Z" fill="%s" stroke="#fff" stroke-width="2"><title>%s</title></path>',
                    $cx, $cy, $x1, $y1, $r, $r, $large, $x2, $y2, e($s['color']),
                    e($s['label'] . ': ' . $s['value'] . ' (' . round($share * 100) . '%)')
                );
                if ($share >= 0.08) {
                    $mid = $angle + $sweep / 2;
                    $svg .= sprintf(
                        '<text x="%.2f" y="%.2f" text-anchor="middle" dominant-baseline="middle" font-size="15" font-weight="700" fill="%s">%d%%</text>',
                        $cx + $r * 0.62 * cos($mid), $cy + $r * 0.62 * sin($mid), e($s['text'] ?? '#fff'), (int) round($share * 100)
                    );
                }
                $angle = $end;
            }
        }
    }
    $svg .= '</svg>';

    $legend = '';
    foreach ($slices as $s) {
        $pct  = $total > 0 ? (int) round((int) $s['value'] / $total * 100) : 0;
        $name = e($s['label']);
        if (!empty($s['href'])) {
            $name = '<a href="' . e($s['href']) . '">' . $name . '</a>';
        }
        $legend .= '<li><span class="swatch" style="background:' . e($s['color']) . '"></span>'
                 . '<span class="legend-name">' . $name . '</span>'
                 . '<span class="legend-num"><strong>' . (int) $s['value'] . '</strong> <small>' . $pct . '%</small></span></li>';
    }

    return '<section class="chart-card"><div class="chart-head"><h3>' . e($title) . '</h3>'
         . '<span class="chart-total"><strong>' . $total . '</strong> total</span></div>'
         . '<div class="chart-body">' . $svg . '<ul class="legend">' . $legend . '</ul></div></section>';
}
