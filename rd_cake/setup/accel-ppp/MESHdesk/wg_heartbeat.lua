#!/usr/bin/lua

-- Include libraries
package.path = "libs/?.lua;" .. package.path

--[[--
This script will typically be started during the setup of Wireguard

--]]--a

require("rdLogger");
debug 	    = true;

local socket    = require("socket");
local l         = rdLogger();

local int_light     = 60
local int_sampling  = 60
local cntr_light    = 0;
local cntr_sampling = 0;

function log(m,p)
	if(debug)then                                                                                     
        print(m); --Print to std out when debug set                                                                                   
	end
    l:log(m,p)                              
end
                                                                                                       
function sleep(sec)
	socket.select(nil, nil, sec)                                                                          
end 

local function light_report()
    log("Light Reporting")
    local result = os.execute("/etc/MESHdesk/reporting/wg_report_to_server.lua light")
    if result ~= true then
        log("Light report execution failed", "ERROR")
    end
    return result
end

function reporting_loop()
    local loop = true;   
    while(loop)do          
	    sleep(1);
	    cntr_light = cntr_light + 1;
	    if(cntr_light == int_light)then
	        cntr_light = 0;
	        light_report();
	    end 
    end
end

reporting_loop();

