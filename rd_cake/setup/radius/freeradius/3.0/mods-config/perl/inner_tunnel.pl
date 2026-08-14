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


use vars qw(    
    %RAD_REQUEST 
    %RAD_CHECK 
    %RAD_REPLY 
    %RAD_CONFIG 
    $dbh 
    %conf
    $conn_valid
    $return
    $stmt_vlan
);

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
    $stmt_vlan  = $dbh->prepare(q{
        SELECT permanent_users.username,permanent_users.session_limit,active,realm_vlans.vlan FROM permanent_users 
        LEFT JOIN realm_vlans ON realm_vlans.id=permanent_users.realm_vlan_id 
        WHERE permanent_users.username=? LIMIT 1;
    });
}

sub CLONE {
    read_conf();
    _ensure_dbh();
}

# Function to handle authorize
sub authorize { 
    $return = RLM_MODULE_OK;    
    &personal_vlan;
    return $return;
}

# Function to handle authenticate
sub authenticate {
    # For debugging purposes only
#       &log_request_attributes;

  
}

# Function to handle preacct
sub preacct {
    # For debugging purposes only
#       &log_request_attributes;

    $return = RLM_MODULE_OK;
   
    return $return;
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
    &radiusd::radlog(RAD_LOG_DEBUG,"Brannewyn het nie brieke nie");
}

sub log_request_attributes {
    # This shouldn't be done in production environments!
    # This is only meant for debugging!
    for (keys %RAD_REQUEST) {
            &radiusd::radlog(1, "RAD_REQUEST: $_ = $RAD_REQUEST{$_}");
    }
}

sub personal_vlan{

    #---Set the User-Name for the accounting---

    $RAD_REPLY{'User-Name'} = $RAD_REQUEST{'User-Name'};    

    $stmt_vlan->execute($RAD_REQUEST{'User-Name'});     
    while(my $row = $stmt_vlan->fetchrow_hashref()){        
            formulate_reply($row);
    }
    $stmt_vlan->finish();
}


sub formulate_reply{
    my ($row) = @_;
      
    #---- SAMPLE STRUCTURE ---
    #$VAR1 = {
    #    'username' => 'unit2@jhb-south',
    #    'active'   => 1,
    #    'vlan'     => 50
    #};
    #------------------------
    
    if($row->{'active'} == 0){
        &radiusd::radlog("2", "Username $row->{'username'} account disabled");
        return;   
    }
    
    if($row->{'vlan'}){
        $RAD_REPLY{'Tunnel-Medium-Type'} = "IEEE-802";
        $RAD_REPLY{'Tunnel-Type'} = "VLAN";
		$RAD_REPLY{'Tunnel-Private-Group-ID'} = $row->{'vlan'};
		$return = RLM_MODULE_UPDATED;		    
    } 

    if($row->{'session_limit'}){
            if($row->{'session_limit'} > 0){
                $RAD_CHECK{'Simultaneous-Use'} = $row->{'session_limit'};
            }
    }
}

