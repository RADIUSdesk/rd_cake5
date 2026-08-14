<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

class VoucherCommand extends Command
{
    protected ?object $Vouchers = null;
    protected UsageCommand $Usage;

    public function initialize(): void
    {
        parent::initialize();
        $this->Vouchers = $this->fetchTable('Vouchers');
        
        // Instantate the utility class directly to call helper methods 
        $this->Usage = new UsageCommand();
        $this->Usage->initialize();
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $qr = $this->Vouchers->find()
            ->where(['OR' => [['Vouchers.status' => 'new'], ['Vouchers.status' => 'used']]])
            ->all();

        foreach ($qr as $i) {
            $this->process_voucher($i->name, $io);
        }

        return static::CODE_SUCCESS;
    }

    private function process_voucher(string $name, ConsoleIo $io): void
    {

        $io->out("<info>Voucher => $name</info>");

        // Test for depleted
        $ret_val = $this->Usage->time_left_from_login($name);

        $time_left_from_login = $ret_val[0] ?? null;
        $time_avail           = $ret_val[1] ?? null;

        if ($time_left_from_login) {
            if ($time_left_from_login === 'depleted') {
                $q_r = $this->Vouchers->find()->where(['Vouchers.name' => $name])->first();
                if ($q_r) {
                    $d = [
                        'perc_time_used' => 100,
                        'status'         => 'depleted'
                    ];
                    if ($time_avail) {
                        $d['time_cap']  = $time_avail;
                        $d['time_used'] = $time_avail;
                    }
                    $this->Vouchers->patchEntity($q_r, $d);
                    $this->Vouchers->save($q_r);
                }
            } else {
                if ($time_avail) {
                    $time_used = $time_avail - $time_left_from_login;
                    $q_r = $this->Vouchers->find()->where(['Vouchers.name' => $name])->first();
                    if ($q_r) {
                        $d = [
                            'time_cap'  => $time_avail,
                            'time_used' => $time_used
                        ];
                        $this->Vouchers->patchEntity($q_r, $d);
                        $this->Vouchers->save($q_r);
                    }
                }
            }
        }

        // Test for expired
        $time_left_from_expire = $this->Usage->time_left_from_expire($name);
        if ($time_left_from_expire) {
            if ($time_left_from_expire === 'expired') {
                $q_r = $this->Vouchers->find()->where(['Vouchers.name' => $name])->first();
                if ($q_r) {
                    $d = [
                        'perc_time_used' => 100,
                        'status'         => 'expired'
                    ];
                    $this->Vouchers->patchEntity($q_r, $d);
                    $this->Vouchers->save($q_r);
                }
            }
        }
    }
}

