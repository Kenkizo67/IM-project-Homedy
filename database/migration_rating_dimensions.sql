-- For an EXISTING homedy database (keeps your data and old reviews).
-- Old reviews keep their star score as "Overall Satisfaction"; the new detailed ratings stay empty for them.
-- Run AFTER migration_price_reviews.sql.
USE homedy;

ALTER TABLE reviews
  ADD COLUMN quality_rating TINYINT NULL AFTER rating,
  ADD COLUMN punctuality_rating TINYINT NULL AFTER quality_rating,
  ADD COLUMN professionalism_rating TINYINT NULL AFTER punctuality_rating,
  ADD COLUMN efficiency_rating TINYINT NULL AFTER professionalism_rating,
  ADD CONSTRAINT chk_quality_rating CHECK (quality_rating IS NULL OR quality_rating BETWEEN 1 AND 5),
  ADD CONSTRAINT chk_punctuality_rating CHECK (punctuality_rating IS NULL OR punctuality_rating BETWEEN 1 AND 5),
  ADD CONSTRAINT chk_professionalism_rating CHECK (professionalism_rating IS NULL OR professionalism_rating BETWEEN 1 AND 5),
  ADD CONSTRAINT chk_efficiency_rating CHECK (efficiency_rating IS NULL OR efficiency_rating BETWEEN 1 AND 5);
