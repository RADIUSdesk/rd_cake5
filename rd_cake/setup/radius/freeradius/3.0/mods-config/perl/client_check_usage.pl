#! usr/bin/perl -w
use strict;

# use ...
# This is very important!
use vars qw(%RAD_REQUEST %RAD_CHECK %RAD_REPLY);
use constant RLM_MODULE_OK=> 2;# /* the module is OK,continue */
use constant RLM_MODULE_UPDATED=> 8;# /* OK (pairs modified) */
use constant RLM_MODULE_REJECT=> 0;# /* immediately reject therequest */
use constant RLM_MODULE_NOOP=> 7;

# Same as src/include/radiusd.h
use constant	L_DBG=>   1;
use constant	L_AUTH=>  2;
use constant	L_INFO=>  3;
use constant	L_ERR=>   4;
use constant	L_PROXY=> 5;
use constant	L_ACCT=>  6;

my $int_max = 4294967296;

sub authorize {

    #We will reply, depending on the usage
    #If FRBG-Total-Data is larger than the 32-bit limit we have to set a Gigaword attribute

    if(exists($RAD_CHECK{'Rd-Data-Used'})){
        #Get the Total data
        my $total_data = $RAD_CHECK{'Rd-Data-Limit-Amount'};
        if($RAD_CHECK{'Rd-Data-Limit-Unit'} =~ /kb/i){
           $total_data = $total_data * 1024; 
        }
        if($RAD_CHECK{'Rd-Data-Limit-Unit'} =~ /mb/i){
           $total_data = $total_data * 1024 * 1024; 
        }
        if($RAD_CHECK{'Rd-Data-Limit-Unit'} =~ /gb/i){
           $total_data = $total_data * 1024 * 1024 * 1024; 
        }
        if($RAD_CHECK{'Rd-Data-Limit-Unit'} =~ /tb/i){
           $total_data = $total_data * 1024 * 1024 * 1024 * 1024; 
        }
           
        if(($total_data - $RAD_CHECK{'Rd-Data-Used'})<0){
            $RAD_REPLY{'Reply-Message'} = "Sorry.. the router has reached its monthly data limit";
            return RLM_MODULE_REJECT;
        }
    }
      
    return RLM_MODULE_NOOP;
}
