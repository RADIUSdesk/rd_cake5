DROP PROCEDURE IF EXISTS add_connect_and_redirect;

DELIMITER //
CREATE PROCEDURE add_connect_and_redirect()
BEGIN

    if not exists (select * from information_schema.columns
        where column_name = 'connect_and_redirect' and table_name = 'ap_profile_exit_captive_portals' and table_schema = DATABASE()) then
        alter table ap_profile_exit_captive_portals add column `connect_and_redirect` tinyint(1) NOT NULL DEFAULT '0';
    end if;
    
    if not exists (select * from information_schema.columns
        where column_name = 'connect_and_redirect_url' and table_name = 'ap_profile_exit_captive_portals' and table_schema = DATABASE()) then
        alter table ap_profile_exit_captive_portals add column `connect_and_redirect_url` varchar(255) NOT NULL DEFAULT '';
    end if;
    
    if not exists (select * from information_schema.columns
        where column_name = 'connect_and_redirect' and table_name = 'mesh_exit_captive_portals' and table_schema = DATABASE()) then
        alter table mesh_exit_captive_portals add column `connect_and_redirect` tinyint(1) NOT NULL DEFAULT '0';
    end if;
    
    if not exists (select * from information_schema.columns
        where column_name = 'connect_and_redirect_url' and table_name = 'mesh_exit_captive_portals' and table_schema = DATABASE()) then
        alter table mesh_exit_captive_portals add column `connect_and_redirect_url` varchar(255) NOT NULL DEFAULT '';
    end if;    

END//

DELIMITER ;
CALL add_connect_and_redirect;
