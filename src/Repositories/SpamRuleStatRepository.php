<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories;

use VEximweb\Plugin\RSpamd\Core\Models\SpamRuleStat;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\SpamRuleStatRepositoryInterface;

class SpamRuleStatRepository implements SpamRuleStatRepositoryInterface
{
    protected $model;

    public function __construct(SpamRuleStat $model)
    {
        $this->model = $model;
    }

    public function incrementOrCreate(string $ruleName, string $date): void
    {
        $stat = $this->model->firstOrNew([
            'rule_name' => $ruleName,
            'date' => $date,
        ]);
        
        $stat->hit_count = $stat->hit_count + 1;
        $stat->updated_at = now();
        
        if (!$stat->exists) {
            $stat->created_at = now();
        }
        
        $stat->save();
    }

    public function batchIncrementOrCreate(array $ruleNames, string $date): void
    {
        $uniqueRules = array_unique($ruleNames);
        
        foreach ($uniqueRules as $ruleName) {
            $this->incrementOrCreate($ruleName, $date);
        }
    }
}