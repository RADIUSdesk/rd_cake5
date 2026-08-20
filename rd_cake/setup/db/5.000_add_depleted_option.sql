DROP PROCEDURE IF EXISTS add_depleted_option;

DELIMITER //
CREATE PROCEDURE add_depleted_option()
BEGIN

    -- Scenario 1: As die kolom NOG NIE bestaan nie, skep dit met 'depleted'
    IF NOT EXISTS (
        SELECT * FROM information_schema.columns
        WHERE column_name = 'admin_state' 
          AND table_name = 'permanent_users' 
          AND table_schema = DATABASE()
    ) THEN
        ALTER TABLE permanent_users 
        ADD COLUMN admin_state ENUM('active', 'suspended', 'terminated', 'pending', 'expired', 'trial', 'locked', 'depleted') 
        NOT NULL DEFAULT 'active' AFTER active;
        
    -- Scenario 2: As die kolom REEDS bestaan, opdateer dit om 'depleted' in te sluit
    ELSE
        ALTER TABLE permanent_users 
        MODIFY COLUMN admin_state ENUM('active', 'suspended', 'terminated', 'pending', 'expired', 'trial', 'locked', 'depleted') 
        NOT NULL DEFAULT 'active';
    END IF;
    
    IF NOT EXISTS (
        SELECT * FROM information_schema.columns
        WHERE column_name = 'temporary_access_until' 
          AND table_name = 'permanent_users' 
          AND table_schema = DATABASE()
    ) THEN
        ALTER TABLE permanent_users
        ADD COLUMN temporary_access_until DATETIME NULL;
    END IF;
    

END//

DELIMITER ;
CALL add_depleted_option;
