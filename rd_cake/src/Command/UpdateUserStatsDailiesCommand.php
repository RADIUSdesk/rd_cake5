<?php
// As www-data
// cd /var/www/html/cake4/rd_cake && bin/cake update_user_stats_dailies 

namespace App\Command;

use Cake\Command\Command; 
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\I18n\DateTime; 

use App\Model\Table\UserStatsTable;
use App\Model\Table\UserStatsDailiesTable;
use App\Model\Table\UserSettingsTable;
use App\Model\Table\DynamicClientsTable;
use App\Model\Table\TimezonesTable;

class UpdateUserStatsDailiesCommand extends Command 
{

    protected $startDate      = null;
    protected $endDate        = null;
    protected $nasTimezone    = [];
    protected $time_zone      = 'UTC'; //Default for timezone
   // protected   $time_zone            = 'Africa/Johannesburg';

    protected UserStatsTable $UserStats;
    protected UserStatsDailiesTable $UserStatsDailies;
    protected UserSettingsTable $UserSettings;
    protected DynamicClientsTable $DynamicClients;
    protected TimezonesTable $Timezones;

    public function initialize():void{
        parent::initialize();
        $this->UserStats        = $this->fetchTable('UserStats');
        $this->UserStatsDailies = $this->fetchTable('UserStatsDailies');
        $this->UserSettings     = $this->fetchTable('UserSettings');
        $this->DynamicClients   = $this->fetchTable('DynamicClients');
        $this->Timezones        = $this->fetchTable('Timezones');
    }
 
    public function execute(Arguments $args, ConsoleIo $io):int {
    
        $io->info("Start Porting of the user_stats table's data to user_stats_dailies table");
        
        $startAt = $this->_getStartAt();
        if($startAt){
            $startOfToday = DateTime::now()->startOfDay();
            if($startAt == $startOfToday){
                $io->success("Porting up to date - Bye (".$startAt->toCookieString().")");
            }else{
                $this->startDate = $startAt;
                $this->endDate   = $startOfToday;                        
                $io->info("Start at ".$this->startDate->toCookieString());
                $io->info("END at ".$this->endDate->toCookieString()); 
                
                $this->_buildNasTimeZoneList();
                $this->doesWork($io); 
               // $this->_doWork();    
                //Update to the start of today*/
                $this->_updateStartAt();
            }
        }else{
            $io->warning("user_stats table seems empty - nothing to port");
        }
        return static::CODE_SUCCESS;
    }
      
    private function doesWork($io){

        while ($this->startDate < $this->endDate) {
            $dayStart   = $this->startDate;
            $dayEnd     = $dayStart->addDays(1)->subSeconds(1);

            $io->warning("==Section Starts " . $dayStart->toCookieString().'==');
            $io->warning("==Section Ends " . $dayEnd->toCookieString().'==');

            $uniqueTimezones = array_unique(array_values($this->nasTimezone));

            if (count($uniqueTimezones) === 1) {
                $this->_addToDailies($dayStart, $dayEnd, $uniqueTimezones[0]);
            } else {
                foreach ($this->nasTimezone as $nasIdentifier => $timezone) {
                    $this->_addToDailies($dayStart, $dayEnd, $timezone, $nasIdentifier);
                }
            }

            $this->startDate = $dayStart->addDays(1);
        }
    }
       
    private function _addToDailies($dayStart,$dayEnd,$timezone,$nasidentifier = null){ //nasidentifier is optional
    
        $dayStartTxt= $dayStart->i18nFormat('yyyy-MM-dd HH:mm:ss');
        $dayEndTxt  = $dayEnd->i18nFormat('yyyy-MM-dd HH:mm:ss');
        
        $query      = $this->UserStats->find();               
        $tz         = $timezone;
        
        $time_start = $query->func()->CONVERT_TZ([
            "'$dayStartTxt'"  => 'literal',
            "'$tz'"          => 'literal',
            "'+00:00'"       => 'literal',
        ]);
        
        $time_end = $query->func()->CONVERT_TZ([
            "'$dayEndTxt'"    => 'literal',
            "'$tz'"           => 'literal',
            "'+00:00'"        => 'literal',
        ]);
        
        $where = [
            'timestamp >=' => $time_start,
            'timestamp <=' => $time_end
        ];
        if($nasidentifier){
            $where['nasidentifier'] = $nasidentifier;
        }                              
        $query
            ->select([
                'user_stat_id'      => 'id',
                'username',
                'realm',
                'nasidentifier',
                'callingstationid',
                'timestamp'          => $dayStart->getTimestamp(),
                'acctinputoctets'    => $query->func()->sum('acctinputoctets'),
                'acctoutputoctets'   => $query->func()->sum('acctoutputoctets'),
            ])
            ->distinct([
                'username',
                'realm',
                'nasipaddress',
                'nasidentifier',
                'callingstationid'
            ])
            ->where($where);
        
        // Execute the query and get the results
        $results = $query->all();       
        foreach ($results as $row) {
            //print_r($row->username);
            //FIXME - JUN2026 - NOTE set created to timestamp 
            $row->created = $row->timestamp;
            $this->UserStatsDailies->save($this->UserStatsDailies->newEntity($row->toArray()));
        }  
    }
      
    //Determine where we need to start from     
    private function _getStartAt(){
        $dateTime = null;
        // Try to find the 'UserStatsDailiesStoppedAt' setting
        $userSetting = $this->UserSettings
            ->find()
            ->where(['user_id' => -1, 'name' => 'UserStatsDailiesStoppedAt'])
            ->first();

        if ($userSetting) {
            $dateTime = DateTime::createFromTimestamp($userSetting->value)->startOfDay();
        } else {
            // Fallback to the first UserStats entry
            $firstUserStat = $this->UserStats
                ->find()
                ->orderBy(['timestamp' => 'ASC'])
                ->first();

            if ($firstUserStat) {
                $time         = new DateTime($firstUserStat->timestamp);
                $dateTime   = $time->startOfDay();
            }
        }
        return $dateTime;
    }
    
    private function _buildNasTimeZoneList(){
    
        $nasList = $this->UserStats->find()
            ->where(['UserStats.timestamp >=' => $this->startDate,'UserStats.timestamp <=' => $this->endDate])
            ->group(['nasidentifier'])
            ->all();
    
        foreach($nasList as $nas){
            $this->nasTimezone[$nas->nasidentifier] = $this->_findTimeZoneFor($nas->nasidentifier); 
        } 
    }
    
    private function _findTimeZoneFor($nasidentifier){
    
        $time_zone = 'UTC'; //Sane Default
        $dynamicClient   = $this->DynamicClients->find()
            ->where(['DynamicClients.nasidentifier' => $nasidentifier])
            ->first();
            
        if($dynamicClient){
            if($dynamicClient !== ''){    
                $tz_id      = $dynamicClient->timezone;
                $timezone   = $this->Timezones->find()->where(['Timezones.id' => $tz_id])->first();
                if($timezone){
                    $time_zone = $timezone->name;
                }
            } 
        }
        return $time_zone;
    }       
    
    private function _updateStartAt(){

        $startOfDay = DateTime::now()->startOfDay();
        $userSetting = $this->UserSettings
            ->find()
            ->where(['user_id' => -1, 'name' => 'UserStatsDailiesStoppedAt'])
            ->first();

        if ($userSetting) {
            $userSetting->value = $startOfDay->toUnixString();
        } else {
            $userSetting = $this->UserSettings->newEntity([
                'user_id' => -1,
                'name' => 'UserStatsDailiesStoppedAt',
                'value' => $startOfDay->toUnixString(),
            ]);
        }

        $this->UserSettings->save($userSetting);
    }
}
