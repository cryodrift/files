DROP TRIGGER IF EXISTS set_created_atfiles;
--END;

DROP TRIGGER IF EXISTS set_updated_atfiles;
--END;

CREATE TRIGGER IF NOT EXISTS set_updated_atfiles
    AFTER UPDATE
    ON files
    FOR EACH ROW
    WHEN (SELECT trigger_update
          FROM trigger_control) = 1
BEGIN
    UPDATE trigger_control SET trigger_update = 0;
    UPDATE files
    SET changed = CURRENT_TIMESTAMP
    WHERE id = NEW.id;
    UPDATE trigger_control SET trigger_update = 1;
END;

