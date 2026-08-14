#
#  This program is free software; you can redistribute it and/or modify
#  it under the terms of the GNU General Public License as published by
#  the Free Software Foundation; either version 2 of the License, or
#  (at your option) any later version.
#
#  This program is distributed in the hope that it will be useful,
#  but WITHOUT ANY WARRANTY; without even the implied warranty of
#  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#  GNU General Public License for more details.
#
#  You should have received a copy of the GNU General Public License
#  along with this program; if not, write to the Free Software
#  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA
#
#  Copyright 2002  The FreeRADIUS server project
#  Copyright 2002  Boian Jordanov <bjordanov@orbitel.bg>
#

#
# Example code for use with rlm_perl
#
# You can use every module that comes with your perl distribution!
#

use strict;
use warnings;
use v5.10; # for say() function

use DBI;
use Data::Dumper;
use DateTime;

use vars qw(%RAD_REQUEST %RAD_CHECK %RAD_REPLY %RAD_CONFIG $dbh %conf $dbh $stmt_fup_comps $stmt_data_used $conn_valid %comp_limits $default_tz $return $client_type $stmt_fnd_appl $stmt_add_appl $stmt_upd_appl $stmt_bc_start $stmt_nas_type $stmt_del_appl);

# This is hash wich hold original request from radius
#my %RAD_REQUEST;
# In this hash you add values that will be returned to NAS.
#my %RAD_REPLY;
#This is for check items
#my %RAD_CHECK;

#
# This the remapping of return values
#
use constant    RLM_MODULE_REJECT=>    0;#  /* immediately reject the request */
use constant    RLM_MODULE_FAIL=>      1;#  /* module failed, don't reply */
use constant    RLM_MODULE_OK=>        2;#  /* the module is OK, continue */
use constant    RLM_MODULE_HANDLED=>   3;#  /* the module handled the request, so stop. */
use constant    RLM_MODULE_INVALID=>   4;#  /* the module considers the request invalid. */
use constant    RLM_MODULE_USERLOCK=>  5;#  /* reject the request (user is locked out) */
use constant    RLM_MODULE_NOTFOUND=>  6;#  /* user not found */
use constant    RLM_MODULE_NOOP=>      7;#  /* module succeeded without doing anything */
use constant    RLM_MODULE_UPDATED=>   8;#  /* OK (pairs modified) */
use constant    RLM_MODULE_NUMCODES=>  9;#  /* How many return codes there are */



#
# This the RADIUS log type
#  
use constant    RAD_LOG_DEBUG=>  0; 
use constant    RAD_LOG_AUTH=>   1; 
use constant    RAD_LOG_PROXY=>  2; 
use constant    RAD_LOG_INFO=>   3; 
use constant    RAD_LOG_ERROR=>  4; 


#___ RADIUSdesk _______
sub read_conf {
   $conf{'db_name'}     = "rd";
   $conf{'db_host'}     = "127.0.0.1";
   $conf{'db_user'}     = "rd";
   $conf{'db_passwd'}   = "rd";
   #$conf{'db_socket'}   = "/run/mysqld/mysqld.sock";
}

# ---- Build a robust DSN ----
sub _dsn {
    # Prefer UNIX socket when local (avoids TCP idle issues)
    if ($conf{'db_host'} && $conf{'db_host'} eq 'localhost' && $conf{'db_socket'}) {
        return "DBI:mysql:database=$conf{'db_name'};mysql_socket=$conf{'db_socket'}";
    }
    my $host = $conf{'db_host'} // '127.0.0.1';
    my $port = $conf{'db_port'} // 3306;
    return "DBI:mysql:database=$conf{'db_name'};host=$host;port=$port";
}

# ---- Connect or reconnect if ping fails ----
sub _ensure_dbh {
    # Happy path: live handle
    if (defined $dbh && eval { $dbh->ping }) {
        $conn_valid = 1;
        return 1;
    }

    # Connect with useful timeouts and auto-reconnect
    my %attr = (
        RaiseError             => 1,
        PrintError             => 0,
        AutoCommit             => 1,
        mysql_auto_reconnect   => 1,    # reconnect on idle drops
        mysql_connect_timeout  => 5,    # seconds
        mysql_read_timeout     => 20,
        mysql_write_timeout    => 20,
        mysql_enable_utf8mb4   => 1,
        # mysql_server_prepare => 1,    # optional; fine to leave off
    );

    $dbh = DBI->connect(_dsn(), $conf{'db_user'}, $conf{'db_passwd'}, \%attr);
    if ($DBI::err) {
        &radiusd::radlog(RAD_LOG_ERROR, "DB Connect Error. $DBI::errstr");
        $conn_valid = 0;
        return 0;
    }

    # Session tuning (keeps short-lived idle disconnects at bay)
    eval {
        $dbh->do("SET NAMES utf8mb4");
        $dbh->do("SET SESSION wait_timeout=600, interactive_timeout=600"); # 10 min
    };

    _prepare_statements();              # <<< IMPORTANT after any reconnect
    $conn_valid = 1;
    return 1;
}

# ---- (Re)prepare statements; call after every (re)connect ----
sub _prepare_statements {
    $stmt_fup_comps = $dbh->prepare(q{
        SELECT id,if_condition,time_start,time_end,data_amount,data_unit,action,action_amount,ip_pool,vlan
        FROM profile_fup_components WHERE profile_id=?
    });

    $stmt_data_used = $dbh->prepare(q{
        SELECT IFNULL(SUM(acctinputoctets)+SUM(acctoutputoctets),0) AS data_used
        FROM user_stats WHERE username=? AND timestamp >= ?
    });

    $stmt_fnd_appl = $dbh->prepare(q{
        SELECT id FROM applied_fup_components WHERE username=? LIMIT 1
    });

    $stmt_add_appl = $dbh->prepare(q{
        INSERT INTO applied_fup_components(username,profile_fup_component_id,created,modified)
        VALUES(?,?,NOW(),NOW())
    });

    $stmt_del_appl = $dbh->prepare(q{
        DELETE FROM applied_fup_components WHERE username=?
    });

    $stmt_upd_appl = $dbh->prepare(q{
        UPDATE applied_fup_components SET profile_fup_component_id=?, modified=NOW() WHERE id=?
    });

    $stmt_bc_start = $dbh->prepare(q{
        SELECT extra_value FROM permanent_users WHERE username=? AND extra_name='BCStartDay'
    });

    $stmt_nas_type = $dbh->prepare(q{
        SELECT type FROM dynamic_clients WHERE nasidentifier=?
    });
}

sub CLONE {
    read_conf();
    _ensure_dbh();
}

# Function to handle authorize
sub authorize {
    # For debugging purposes only
#       &log_request_attributes;

    # Here's where your authorization code comes
    # You can call another function from here:
       
    #Reset these values to sane defaults
    $default_tz  = 'Africa/Johannesburg';
    $return      = RLM_MODULE_NOOP;
    $client_type = 'Mikrotik-API'; #Maybe future feature to decide what to reply ...
    
    &fup;

    return $return;
}

# Function to handle authenticate
sub authenticate {
    # For debugging purposes only
#       &log_request_attributes;

    if ($RAD_REQUEST{'User-Name'} =~ /^baduser/i) {
            # Reject user and tell him why
            $RAD_REPLY{'Reply-Message'} = "Denied access by rlm_perl function";
            return RLM_MODULE_REJECT;
    } else {
            # Accept user and set some attribute
            $RAD_REPLY{'h323-credit-amount'} = "100";
            return RLM_MODULE_OK;
    }
}

# Function to handle preacct
sub preacct {
    # For debugging purposes only
#       &log_request_attributes;

    return RLM_MODULE_OK;
}

# Function to handle accounting
sub accounting {
    # For debugging purposes only
#       &log_request_attributes;

    # You can call another subroutine from here
    &test_call;

    return RLM_MODULE_OK;
}

# Function to handle checksimul
sub checksimul {
    # For debugging purposes only
#       &log_request_attributes;

    return RLM_MODULE_OK;
}

# Function to handle pre_proxy
sub pre_proxy {
    # For debugging purposes only
#       &log_request_attributes;

    return RLM_MODULE_OK;
}

# Function to handle post_proxy
sub post_proxy {
    # For debugging purposes only
#       &log_request_attributes;

    return RLM_MODULE_OK;
}

# Function to handle post_auth
sub post_auth {
    # For debugging purposes only
#       &log_request_attributes;

    return RLM_MODULE_OK;
}

# Function to handle xlat
sub xlat {
    # For debugging purposes only
#       &log_request_attributes;

    # Loads some external perl and evaluate it
    my ($filename,$a,$b,$c,$d) = @_;
    &radiusd::radlog(1, "From xlat $filename ");
    &radiusd::radlog(1,"From xlat $a $b $c $d ");
    local *FH;
    open FH, $filename or die "open '$filename' $!";
    local($/) = undef;
    my $sub = <FH>;
    close FH;
    my $eval = qq{ sub handler{ $sub;} };
    eval $eval;
    eval {main->handler;};
}

# Function to handle detach
sub detach {
    # For debugging purposes only
#       &log_request_attributes;

    # Do some logging.
    &radiusd::radlog(0,"rlm_perl::Detaching. Reloading. Done.");
}

#
# Some functions that can be called from other functions
#

sub test_call {
    # Some code goes here
    &radiusd::radlog(RAD_LOG_DEBUG,"RADIUSdesk - This is a test call");
}

sub log_request_attributes {
    # This shouldn't be done in production environments!
    # This is only meant for debugging!
    for (keys %RAD_REQUEST) {
            &radiusd::radlog(1, "RAD_REQUEST: $_ = $RAD_REQUEST{$_}");
    }
}

sub fup {
    _ensure_dbh() or return RLM_MODULE_FAIL;
    $stmt_fup_comps->execute($RAD_CONFIG{'Rd-Fup-Profile-Id'});
    my %limits;
    my $bloc_id = undef;
      
    while(my $row = $stmt_fup_comps->fetchrow_hashref()){  
        if($row->{'if_condition'} eq 'time_of_day'){
            if(check_time_of_day($row)){
                #Block action always stop everything further
                if($return == RLM_MODULE_USERLOCK){
                    #say $RAD_REPLY{'Reply-Message'};
                    $bloc_id = $row->{'id'};
                    last; #We do not need to do anything else
                }
                $limits{$row->{'id'}} = $row;               
            }
        }else{
        
            #These are day_usage week_usage or month_usage limits
            if(check_usage($row)){
                #Block action always stop everything further
                if($return == RLM_MODULE_USERLOCK){
                    #say $RAD_REPLY{'Reply-Message'};
                    $bloc_id = $row->{'id'};
                    last; #We do not need to do anything else
                }
                #Action is increase_speed or decrease_speed
                $limits{$row->{'id'}} = $row;
            }
        }

    }       
    $stmt_fup_comps->finish();
    my $most_decrease = undef;
    my $least_increase  = undef;
    if($return != RLM_MODULE_USERLOCK){ #Determine the 'winner'
     
        while (my($key, $val) = each (%limits)){
            # do whatever you want with $key and $value here ...
            $val = $limits{$key};

            #Decrease Speed
            if($most_decrease){
                if($val->{'action'} eq 'decrease_speed'){
                    if($val->{'action_amount'} >$most_decrease->{'action_amount'}){
                        $most_decrease = $val;
                    } 
                }
            }else{
                if($val->{'action'} eq 'decrease_speed'){
                    $most_decrease = $val;
                }
            }

            #Increase Speed
            if($least_increase){
                if($val->{'action'} eq 'increase_speed'){
                    if($val->{'action_amount'} <$least_increase->{'action_amount'}){
                        $least_increase = $val;
                    } 
                }
            }else{
                if($val->{'action'} eq 'increase_speed'){
                    $least_increase = $val;
                }
            }
        }
            
            
        if($most_decrease){
            #print(Dumper($most_decrease));
            formulate_reply($most_decrease)
        }else{
            if($least_increase){      
                #print(Dumper($least_increase));
                formulate_reply($least_increase);
            }else{
                say "No FUP Speed Adjustment";
                formulate_reply(); #This means there is no limit to apply now
            }
        }
                    
    }else{
            
        #Update the applied_fup_components
        #We monitor this table to detect a transision which should then trigger a disconnect request to apply the new rule / service level on FUP
        _ensure_dbh() or return RLM_MODULE_FAIL;
        $stmt_fnd_appl->execute($RAD_REQUEST{'User-Name'});
        my $r_appl = $stmt_fnd_appl->fetchrow_hashref();
        $stmt_fnd_appl->finish();
        if($r_appl->{'id'}){
            _ensure_dbh() or return RLM_MODULE_FAIL;
            $stmt_upd_appl->execute($bloc_id,$r_appl->{'id'});
            $stmt_upd_appl->finish();  
        }else{
            _ensure_dbh() or return RLM_MODULE_FAIL;   
            $stmt_add_appl->execute($RAD_REQUEST{'User-Name'},$bloc_id);
            $stmt_add_appl->finish();
        }          
    }
}

sub check_time_of_day {
    my($row) = @_;
    if(exists($RAD_CHECK{'Rd-Client-Timezone'})){   
        if($RAD_CHECK{'Rd-Client-Timezone'} ne 'timezone_not_found'){
            $default_tz = $RAD_CHECK{'Rd-Client-Timezone'};
        } 
    }

    my $dt          = DateTime->now( time_zone => $default_tz);
    my $dt_start    = DateTime->now( time_zone => $default_tz);
    my $dt_end      = DateTime->now( time_zone => $default_tz);

    #Determine if we fall within the timeslot of the time_of_day span
    my @time_start = split(':', $row->{'time_start'});
    $dt_start->set_hour($time_start[0]);
    $dt_start->set_minute($time_start[1]);
    $dt_start->set_second(00);

    my @time_end = split(':', $row->{'time_end'});
    $dt_end->set_hour($time_end[0]);
    $dt_end->set_minute($time_end[1]);
    $dt_end->set_second(00);
    if(($dt->epoch >= $dt_start->epoch)&&($dt->epoch <= $dt_end->epoch)){
        if($row->{'action'} eq 'block'){
            $RAD_REPLY{'Reply-Message'} = "Not Available To Use On ".$dt->day_name." at ".$dt->hms(':');
            $return                     = RLM_MODULE_USERLOCK;
        }
        return 1;
    }else{
        return undef;
    }
}

sub check_usage {
    my($row)        = @_;
    my $time_start  = get_start_of($row->{'if_condition'});
    #say $time_start->rfc3339;
    _ensure_dbh() or return RLM_MODULE_FAIL;
    $stmt_data_used->execute($RAD_REQUEST{'User-Name'},$time_start->rfc3339);
    my $result      = $stmt_data_used->fetchrow_hashref();
    $stmt_data_used->finish();

    my $trigger     = $row->{'data_amount'};
    if($row->{'data_unit'} eq 'mb'){
        $trigger = $trigger * 1024 * 1024;
    }
    if($row->{'data_unit'} eq 'gb'){
        $trigger = $trigger * 1024 * 1024 * 1024;
    }

    if($result->{'data_used'} > $trigger){
        if($row->{'action'} eq 'block'){
            $RAD_REPLY{'Reply-Message'} = "$row->{'if_condition'} of $row->{'data_amount'}$row->{'data_unit'} reached";
            $return                     = RLM_MODULE_USERLOCK;
        }
        return 1;
    }else{
        return undef;
    }
    
}

sub get_start_of {
    my ($when) = @_;
    if(exists($RAD_CHECK{'Rd-Client-Timezone'})){   
        if($RAD_CHECK{'Rd-Client-Timezone'} ne 'timezone_not_found'){
            $default_tz = $RAD_CHECK{'Rd-Client-Timezone'};
        } 
    }

    #'day_usage' is default of current day
    my $dt = DateTime->now( time_zone => $default_tz);
    $dt->set_hour(00);
    $dt->set_minute(00);
    $dt->set_second(00);

    if($when eq 'week_usage'){
        while($dt->day_of_week > 1){
            #say $dt->day_of_week;
            $dt->subtract( days => 1 );
        }      
    }

    if($when eq 'month_usage'){
        $dt->set_day(1);
    } 
    
    if($when eq 'month_usage'){       
        #See if there is a extra_name='BCStartDay' value for the Billing Cycle
        my $billing_cycle = 0;       
        if(exists($RAD_CONFIG{'Rd-User-Type'})){
            if($RAD_CONFIG{'Rd-User-Type'} eq 'user'){
                _ensure_dbh() or return RLM_MODULE_FAIL;
                $stmt_bc_start->execute($RAD_REQUEST{'User-Name'});
                my $r_billing_cycle = $stmt_bc_start->fetchrow_hashref();
                if($r_billing_cycle){
                    $billing_cycle = $r_billing_cycle->{'extra_value'};
                }
            }
        }
                              
		if($billing_cycle){
			$dt = findBillingCycleStart($billing_cycle);	
		}else{
	    	$dt->set_day(1);
	   	}
	}
    return $dt;
}


sub findBillingCycleStart{

    my($bc_day) = @_;
    my $dt = DateTime->now( time_zone => $default_tz);
    $dt->set_hour(00);
    $dt->set_minute(00);
    $dt->set_second(00);
       
    my $day_now = $dt->day;
	#say "Day Now IS $day_now";
	#say "Cycle Day Is $bc_day";
	
	my $bc_start;
	
	if(($bc_day > $day_now)&&($day_now < 28)){
		$bc_start = $dt->set_month($dt->month-1);		
		$bc_start = DateTime->new( year => $bc_start->year, month => $bc_start->month , day => $bc_day, time_zone => $default_tz );   		
	}else{
		if($day_now > 28){ #Roll over to next month after 28th (29,30,31 will use this)
			$bc_start = DateTime->new( year => $dt->year, month => $dt->month , day => 29, time_zone => $default_tz );	
		}else{
			$bc_start = DateTime->new( year => $dt->year, month => $dt->month , day => $bc_day , time_zone => $default_tz );
		}
	}
	return $bc_start;
}

sub formulate_reply{
    my ($row) = @_;
    #say Dumper($row);
    
    my $up_value   = $RAD_CONFIG{'Rd-Fup-Bw-Up'};
    my $down_value = $RAD_CONFIG{'Rd-Fup-Bw-Down'};
    my $up_suffix  = "";
    my $down_suffix = "";
    
    #Check if we are testing auth using the GUI - Then we send everything
    my $full_reply = 0; #Default = not full reply
    
    if (defined $RAD_REQUEST{'NAS-IP-Address'} && length $RAD_REQUEST{'NAS-IP-Address'} > 0) {
        if($RAD_REQUEST{'NAS-IP-Address'} eq '127.0.0.1'){
            $full_reply = 1; 
        }
    }
    
    #Check if we can find the type
    if (defined $RAD_REQUEST{'NAS-Identifier'} && length $RAD_REQUEST{'NAS-Identifier'} > 0) {
        _ensure_dbh() or return RLM_MODULE_FAIL;
        $stmt_nas_type->execute($RAD_REQUEST{'NAS-Identifier'});
        my $r_nas_type = $stmt_nas_type->fetchrow_hashref();
        if($r_nas_type){
            $client_type = $r_nas_type->{'type'};
        }   
    }
    
    #If there is no personal VLAN and there is a VLAN defined; assign it 
    if(!defined $RAD_REPLY{'Tunnel-Private-Group-ID'} && $RAD_CONFIG{'Rd-Fup-Vlan'}){
        $RAD_REPLY{'Tunnel-Medium-Type'} = 'IEEE-802';
		$RAD_REPLY{'Tunnel-Type'} = 'VLAN';
		$RAD_REPLY{'Tunnel-Private-Group-ID'} = $RAD_CONFIG{'Rd-Fup-Vlan'};
    }
    
    #If there is no personal Simultaneous-Use defined; assign it 
    if(!defined $RAD_CHECK{'Simultaneous-Use'} && $RAD_CONFIG{'Rd-Fup-Session-Limit'}){
	    $RAD_CHECK{'Simultaneous-Use'} = $RAD_CONFIG{'Rd-Fup-Session-Limit'};
    }
    
    if($RAD_CONFIG{'Rd-Fup-Ip-Pool'}){
        $RAD_REPLY{'Framed-Pool'} = $RAD_CONFIG{'Rd-Fup-Ip-Pool'};
    }
    
    my $component_id = 0; #Set to zero if there are no fup_components involved
    
    if($row){
        if($row->{'action'} eq 'decrease_speed'){
            $up_value   = $up_value-($up_value*($row->{'action_amount'}/100));
            $down_value = $down_value-($down_value*($row->{'action_amount'}/100));
        }
        if($row->{'action'} eq 'increase_speed'){
            $up_value   = $up_value+($up_value*($row->{'action_amount'}/100));
            $down_value = $down_value+($down_value*($row->{'action_amount'}/100));
        }
        
        if($row->{'ip_pool'}){        
            $RAD_REPLY{'Framed-Pool'} = $row->{'ip_pool'};
        }
	if($row->{'vlan'}){
		$RAD_REPLY{'Tunnel-Medium-Type'} = 'IEEE-802';
		$RAD_REPLY{'Tunnel-Type'} = 'VLAN';
		$RAD_REPLY{'Tunnel-Private-Group-ID'} = $row->{'vlan'};
	}
        $component_id = $row->{'id'};        
    }
    
    #Send this one regardless (The NAS should ignore it if not used by it)
    $RAD_REPLY{'WISPr-Bandwidth-Max-Up'}  = int($up_value / 1024);
    $RAD_REPLY{'WISPr-Bandwidth-Max-Down'} = int($down_value / 1024);


    #FOR Mikrotik devices FIXME Add support for IP Pools
    #Accel act as Mikrotik. (Change here if you want them to act like Cisco)
    if(($client_type eq 'Mikrotik-API') or ($client_type eq 'Accel-On-RADIUSdesk') or $full_reply){
        if(($up_value / 1024)>=1){
            $up_suffix  = "k";
            $up_value   = $up_value / 1024;
        }
        if(($up_value / 1024)>=1){
            $up_suffix  = "M";
            $up_value   = $up_value / 1024;
        }
        $up_value = int($up_value);

        if(($down_value / 1024)>=1){
            $down_suffix  = "k";
            $down_value   = $down_value / 1024;
        }

        if(($down_value / 1024)>=1){
            $down_suffix  = "M";
            $down_value   = $down_value / 1024;
        }
        $down_value = int($down_value);
        
        $RAD_REPLY{'Mikrotik-Rate-Limit'} = "$up_value$up_suffix/$down_value$down_suffix";
        if($RAD_CONFIG{'Rd-Fup-Burst-Limit'}){        
            #20M Down 5M Up Burst 40M Down for 10s
            #5M/20M 10M/40M 7M/35M 10/10
            #Mikrotik-Rate-Limit
            my $burst_down = int($down_value+($down_value*($RAD_CONFIG{'Rd-Fup-Burst-Limit'}/100)));
            my $burst_up   = int($up_value+($up_value*($RAD_CONFIG{'Rd-Fup-Burst-Limit'}/100)));
            my $burst_up_th= int($down_value+($down_value*($RAD_CONFIG{'Rd-Fup-Burst-Threshold'}/100)));
            my $burst_down_th= int($up_value+($up_value*($RAD_CONFIG{'Rd-Fup-Burst-Threshold'}/100)));
            my $burts_time = $RAD_CONFIG{'Rd-Fup-Burst-Time'};
            $RAD_REPLY{'Mikrotik-Rate-Limit'} = "$up_value$up_suffix/$down_value$down_suffix $burst_up$up_suffix/$burst_down$up_suffix $burst_up_th$up_suffix/$burst_down_th$up_suffix $burts_time/$burts_time";
        }       
    }
    
    #Add support for Juniper hardware   
    if(($client_type eq 'Juniper')or $full_reply){
        if(($up_value / 1024)>=1){
            $up_suffix  = "k";
            $up_value   = $up_value / 1024;
        }
        if(($up_value / 1024)>=1){
            $up_suffix  = "m";
            $up_value   = $up_value / 1024;
        }
        $up_value = int($up_value);

        if(($down_value / 1024)>=1){
            $down_suffix  = "k";
            $down_value   = $down_value / 1024;
        }

        if(($down_value / 1024)>=1){
            $down_suffix  = "m";
            $down_value   = $down_value / 1024;
        }
        $down_value = int($down_value);
        
        $up_suffix = lc($up_suffix);
        $down_suffix = lc($down_suffix);
        
        $RAD_REPLY{'ERX-Service-Activate:1'} = "DYNAMIC_SVC($up_value$up_suffix,$down_value$down_suffix)";

	    #These two goes together for IP POOLS
        $RAD_REPLY{'ERX-Virtual-Router-Name'} = "default:PPPoE-INSIDE";
	    $RAD_REPLY{'Framed-Pool'} = "SWIMMING-POOL-500";
        $RAD_REPLY{'Framed-IP-Netmask'} = "255.255.255.255";
    }
    
       
    if($row){ 
        
        #Update the applied_fup_components
        #We monitor this table to detect a transision which should then trigger a disconnect request to apply the new rule / service level on FUP
        _ensure_dbh() or return RLM_MODULE_FAIL;
        $stmt_fnd_appl->execute($RAD_REQUEST{'User-Name'});
        my $r_appl = $stmt_fnd_appl->fetchrow_hashref();
        $stmt_fnd_appl->finish();
        if($r_appl->{'id'}){
            _ensure_dbh() or return RLM_MODULE_FAIL;
            $stmt_upd_appl->execute($row->{'id'},$r_appl->{'id'});
            $stmt_upd_appl->finish();  
        }else{  
            _ensure_dbh() or return RLM_MODULE_FAIL;  
            $stmt_add_appl->execute($RAD_REQUEST{'User-Name'},$row->{'id'});
            $stmt_add_appl->finish();
        }  
        #say Dumper($RAD_REPLY{'Mikrotik-Rate-Limit'});
    }else{
        _ensure_dbh() or return RLM_MODULE_FAIL;
        $stmt_del_appl->execute($RAD_REQUEST{'User-Name'}); #Clear any applied_components should there happen to be
    }
}

