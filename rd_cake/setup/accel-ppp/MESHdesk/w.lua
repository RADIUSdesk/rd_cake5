#!/usr/bin/lua
--[[--
Startup script to get the config of the device from the config server

'/etc/wireguard-rd.conf'


[internet]
disabled=0
dns=cloud.radiusdesk.com
url=cake4/rd_cake/nodes/get-config-for-node.json
status_url=cake4/rd_cake/node-reports/submit_report.json
actions_url=cake4/rd_cake/node-actions/get_actions_for.json
protocol=https
http_port=80
https_port=443
ip=164.160.89.129
interface=enp0s8


--]]--

-- Include libraries
package.path        = "libs/?.lua;" .. package.path;
local cjson         = require("cjson");
local socket        = require("socket");
local inifile       = require('inifile');
local result_file   = '/tmp/wg_result_cfg.json'
local config_file   = '/etc/wireguard-rd.conf';
local wg_conf_dir   = '/etc/wireguard/';
local conf_url      = '/cake4/rd_cake/wireguard-servers/get-config-for-server.json';
local txtConf       = '';
local wg_tmp_dir    = '/tmp/';

function afterFetch()
    --Read the results
    local f=io.open(result_file,"r")
    if(f)then
        result_string = f:read("*all")
        --print(result_string);
        r = cjson.decode(result_string);
        if((r.success ~= nil)and(r.success == true))then
            wireguardCleanup();
            buildConfig(r.data);   
        end                                       
    end  
end

function fetchConfig()

    --connecting to the server
    local conf  = inifile.parse(config_file);
    local dns   = conf['internet']['dns'];
    local proto = conf['internet']['protocol'];
    local ip    = conf['internet']['ip'];
    local interface = conf['wireguard']['interface'];
    local id        = getMac(interface);
    
    local current_ip = socket.dns.toip(dns)
    if(current_ip)then
        if(current_ip ~= ip)then
            ip = current_ip; --update it
            conf['internet']['ip'] = ip;
            inifile.save(config_file, conf);
        end
    end
    print(id);        
    local query     = proto..'://'..ip..conf_url;
    local curl_data = 'mac='..id;
    os.remove(result_file)  
    os.execute('curl -G -k -o '..result_file..' -H "Content-Type: application/json" -d \''..curl_data..'\' '..query);
    afterFetch();
end

function  getMac(interface)
	interface = interface or "eth0"
	io.input("/sys/class/net/" .. interface .. "/address")
	t = io.read("*line")
	dashes, count = string.gsub(t, ":", "-")
	dashes = string.upper(dashes)
	return dashes
end

function buildConfig(t)
    for _, instance in ipairs(t or {}) do
      	if instance.Name then
      	    print("Start new file for "..instance.Name);
      	    local txtConf = '[Interface]';
      	    for k, v in pairs(instance.Interface) do   	    
                if(type(v) == 'table')then
                    for _, multi_item in ipairs(v or {}) do
                        --print(k .. ' = ' .. tostring(multi_item)); 
                        txtConf = txtConf.."\n"..k .. ' = ' .. tostring(multi_item);     
                    end
                else
                    --print(k .. ' = ' .. tostring(v)); 
                    txtConf = txtConf.."\n".. k .. ' = ' .. tostring(v)  
                end
      	    end
      	    txtConf = txtConf.."\n";
      	    
      	    for _, peer in ipairs(instance.Peers) do
      	        txtConf = txtConf.."\n"..'[Peer]'; 
      	        for k, v in pairs(peer) do   	    
                    if(type(v) == 'table')then
                        for _, multi_item in ipairs(v or {}) do
                            --print(k .. ' = ' .. tostring(multi_item)); 
                            txtConf = txtConf.."\n"..k .. ' = ' .. tostring(multi_item);     
                        end
                    else
                        --print(k .. ' = ' .. tostring(v)); 
                        txtConf = txtConf.."\n".. k .. ' = ' .. tostring(v)  
                    end
          	    end
          	    txtConf = txtConf.."\n";               
      	    end  	    
      	    --print(txtConf);
      	    writeAndRestart(txtConf,instance.Name);
        end
    end
end

function writeAndRestart(conf_txt, ifname)
    local tmp_file = wg_tmp_dir..ifname..'.conf';
    local conf_file= wg_conf_dir..ifname..'.conf';
    local file = io.open(tmp_file, "w" )
    if( io.type( file ) == "file" ) then
        file:write(conf_txt)
        file:close();
        os.execute('cp '..tmp_file..' '..conf_file);
        os.execute('systemctl stop wg-quick@'..ifname..'.service');
        os.execute('systemctl enable wg-quick@'..ifname..'.service');
        os.execute('systemctl restart wg-quick@'..ifname..'.service');
        os.remove(tmp_file) 	
    end
end

function wireguardCleanup()

    os.execute([[
    for f in /etc/wireguard/wg*.conf; do
      [ -f "$f" ] || continue
      name=$(basename "$f" .conf)
      echo "Stopping and disabling wg-quick@$name..."
      systemctl stop wg-quick@$name 2>/dev/null
      systemctl disable wg-quick@$name 2>/dev/null
      rm -f /etc/systemd/system/multi-user.target.wants/wg-quick@$name.service
      rm -f "$f"
    done
    systemctl daemon-reload
    systemctl reset-failed
    ]]);
    
end


fetchConfig();

