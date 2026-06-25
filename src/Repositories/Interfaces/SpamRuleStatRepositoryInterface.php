<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces;

interface SpamRuleStatRepositoryInterface
{
    public function incrementOrCreate(string $ruleName, string $date): void;
    
    public function batchIncrementOrCreate(array $ruleNames, string $date): void;
}