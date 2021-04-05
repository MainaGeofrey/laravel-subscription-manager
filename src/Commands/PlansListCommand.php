<?php

namespace Rokde\SubscriptionManager\Commands;

use Illuminate\Console\Command;
use Rokde\SubscriptionManager\Models\Plan;

class PlansListCommand extends Command
{
    public $signature = 'plans:list';

    public $description = 'List all plans with their features';

    public function handle()
    {
        $rows = Plan::orderBy('name')
            ->get()
            ->map(function (Plan $plan) {
                return [
                    $plan->name,
                    $plan->features->sortBy('code')->pluck('code')->implode(', '),
                ];
            });

        $this->table(['name', 'features'], $rows->toArray());
    }
}
