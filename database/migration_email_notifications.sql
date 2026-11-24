-- For an EXISTING homedy database: remembers when a payment reminder email was last sent.
USE homedy;
ALTER TABLE payments ADD COLUMN last_reminder DATETIME NULL;
