require( "class" )

-------------------------------------------------------------------------------
------- Wireguard JSON output class -------------------------------------------
-------------------------------------------------------------------------------
class "rdWireguardJson"

--Init function for object
function rdWireguardJson:rdWireguardJson()
	self.version 	    = "1.0.1"
	self.tag	        = "MESHdesk"
	self.priority	    = "debug"
end
        
function rdWireguardJson:getVersion()
	return self.version
end

function rdWireguardJson:showAll()
    return self:_showAll()	
end

--[[--
========================================================
=== Private functions start here =======================
========================================================
--]]--

function rdWireguardJson._showAll(self)
    local a = io.popen('wg show all dump')
    if not a then
        return nil, "Failed to execute wg command"
    end
    
    local str = a:read('*a')
    a:close()
    
    if not str or str:len() == 0 then
        return {}
    end
    
    local interfaces = {}
    local current_interface = nil
    
    for line in str:gmatch("[^\r\n]+") do
        if line:len() > 0 then
            local fields = {}
            for field in line:gmatch("([^\t]+)") do
                table.insert(fields, field)
            end
            
            --print("DEBUG: Found " .. #fields .. " fields: " .. table.concat(fields, " | "))
            
            -- Interface line (first line for each interface)
            if fields[1] and interfaces[fields[1]] == nil then
                current_interface = {
                    private_key = fields[2],
                    public_key = fields[3] ~= "(none)" and fields[3] or nil, --The interface the public and private keys are swapped when compared to the peers
                    listen_port = tonumber(fields[4]),
                    fwmark = fields[5] ~= "off" and tonumber(fields[5]) or nil,
                    peers = {}
                }
                interfaces[fields[1]] = current_interface
                --print("DEBUG: Added interface: " .. fields[1])
                
            -- Peer line (subsequent lines for same interface)
            elseif current_interface and #fields >= 8 then
                local peer = {
                    public_key = fields[2],
                    preshared_key = fields[3] ~= "(none)" and fields[3] or nil,
                    endpoint = fields[4] ~= "(none)" and fields[4] or nil,
                    allowed_ips = fields[5] ~= "(none)" and fields[5] or nil,
                    latest_handshake = tonumber(fields[6]) or 0,
                    rx_bytes = tonumber(fields[7]) or 0,
                    tx_bytes = tonumber(fields[8]) or 0,
                    persistent_keepalive = fields[9] and fields[9] ~= "off" and tonumber(fields[9]) or 0
                }
                table.insert(current_interface.peers, peer)
                --print("DEBUG: Added peer: " .. peer.public_key:sub(1, 8) .. "...")
            else
                --print("DEBUG: Skipped line with " .. #fields .. " fields")
            end
        end
    end
    
    return interfaces
end
