DROP PROCEDURE IF EXISTS add_station_aliases;

DELIMITER //
CREATE PROCEDURE add_station_aliases()
BEGIN

    if not exists (select * from information_schema.columns
    where table_name = 'stations' and table_schema = DATABASE()) then
    
        CREATE TABLE stations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            callingstationid varchar(17) NOT NULL,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );  
        
    end if;
    
    if not exists (select * from information_schema.columns
    where table_name = 'station_aliases' and table_schema = DATABASE()) then
    
        CREATE TABLE station_aliases (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            alias char(255) DEFAULT NULL,
            cloud_id int(11) DEFAULT NULL,
            station_id BIGINT DEFAULT NULL,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_station_aliases_station_id` (`station_id`)
        );  
        
    end if;
    

END//

DELIMITER ;
CALL add_station_aliases;
