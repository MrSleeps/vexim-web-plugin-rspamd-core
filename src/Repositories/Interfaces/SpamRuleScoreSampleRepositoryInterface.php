<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces;

interface SpamRuleScoreSampleRepositoryInterface
{
    public function create(array $data): void;
    
    public function batchCreate(array $samples): void;
}