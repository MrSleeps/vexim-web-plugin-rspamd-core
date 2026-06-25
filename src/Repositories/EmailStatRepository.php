<?php

namespace VEximweb\Plugin\RSpamd\Core\Repositories;

use VEximweb\Plugin\RSpamd\Core\Models\EmailStat;
use VEximweb\Plugin\RSpamd\Core\Models\EmailScoreSample;
use VEximweb\Plugin\RSpamd\Core\Repositories\Interfaces\EmailStatRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use DateTime;

class EmailStatRepository implements EmailStatRepositoryInterface
{
    protected $emailStatModel;
    protected $emailScoreSampleModel;

    public function __construct(EmailStat $emailStatModel, EmailScoreSample $emailScoreSampleModel)
    {
        $this->emailStatModel = $emailStatModel;
        $this->emailScoreSampleModel = $emailScoreSampleModel;
    }

    public function incrementOrCreate(string $hour, string $action, bool $hasVirus, int $incrementBy = 1): void
    {
        $stat = $this->emailStatModel->firstOrNew([
            'hour' => $hour,
            'action' => $action,
            'has_virus' => $hasVirus,
        ]);
        
        $stat->count = $stat->count + $incrementBy;
        $stat->updated_at = now();
        
        if (!$stat->exists) {
            $stat->created_at = now();
        }
        
        $stat->save();
    }

    public function createSample(array $data): void
    {
        $this->emailScoreSampleModel->create($data);
    }

    public function getHourlyStats(string $date, ?string $action = null): array
    {
        $query = $this->emailStatModel
            ->whereDate('hour', $date)
            ->select('hour', 'action', 'count', 'has_virus')
            ->orderBy('hour');

        if ($action) {
            $query->where('action', $action);
        }

        return $query->get()->toArray();
    }

    public function getAggregatedStats(string $startDate, string $endDate): array
    {
        $stats = $this->emailStatModel
            ->whereBetween('hour', [$startDate, $endDate])
            ->select(
                DB::raw('SUM(count) as total_emails'),
                DB::raw('SUM(CASE WHEN action IN ("reject", "discard") OR has_virus = 1 THEN count ELSE 0 END) as total_spam'),
                DB::raw('SUM(CASE WHEN has_virus = 1 THEN count ELSE 0 END) as total_virus'),
                DB::raw('COUNT(DISTINCT action) as unique_actions')
            )
            ->first();

        return [
            'total_emails' => (int) ($stats->total_emails ?? 0),
            'total_spam' => (int) ($stats->total_spam ?? 0),
            'total_virus' => (int) ($stats->total_virus ?? 0),
            'unique_actions' => (int) ($stats->unique_actions ?? 0),
            'spam_percentage' => ($stats->total_emails ?? 0) > 0 
                ? round((($stats->total_spam ?? 0) / ($stats->total_emails ?? 0)) * 100, 2)
                : 0,
        ];
    }
    
    public function getMessageCountsByPeriod(string $startDate, string $endDate, ?string $groupBy = null): array
    {
        $startDateOnly = date('Y-m-d', strtotime($startDate));
        $endDateOnly = date('Y-m-d', strtotime($endDate));

        $query = $this->emailStatModel
            ->whereDate('hour', '>=', $startDateOnly)
            ->whereDate('hour', '<=', $endDateOnly)
            ->select(
                DB::raw('SUM(count) as total_messages'),
                DB::raw('SUM(CASE WHEN has_virus = 1 THEN count ELSE 0 END) as virus_messages'),
                DB::raw('COUNT(DISTINCT action) as unique_actions')
            );

        if ($groupBy === 'day') {
            $query->addSelect(DB::raw('DATE(hour) as period'))
                  ->groupBy(DB::raw('DATE(hour)'))
                  ->orderBy('period');
        } elseif ($groupBy === 'hour') {
            $query->addSelect(DB::raw('HOUR(hour) as hour'))
                  ->groupBy(DB::raw('HOUR(hour)'))
                  ->orderBy('hour');
        } elseif ($groupBy === 'action') {
            $query->addSelect('action')
                  ->groupBy('action')
                  ->orderBy('total_messages', 'desc');
        }

        $results = $query->get();

        if ($groupBy) {
            return $results->toArray();
        }

        $stats = $results->first();
        return [
            'total_messages' => (int) ($stats->total_messages ?? 0),
            'virus_messages' => (int) ($stats->virus_messages ?? 0),
            'unique_actions' => (int) ($stats->unique_actions ?? 0),
            'clean_messages' => (int) (($stats->total_messages ?? 0) - ($stats->virus_messages ?? 0)),
        ];
    }
    
public function getHourlyDistribution(string $date): array
{
    // Use DB::raw with explicit casting to integer
    $results = DB::table('vw_email_stats')
        ->whereDate('hour', $date)
        ->select(
            DB::raw('HOUR(hour) as hour'),
            DB::raw('SUM(count) as message_count'),
            'action',
            'has_virus'
        )
        ->groupBy('hour', 'action', 'has_virus')
        ->orderBy('hour')
        ->get();

    $hourlyData = [];
    for ($i = 0; $i < 24; $i++) {
        $hourlyData[$i] = [
            'hour' => $i,
            'total' => 0,
            'actions' => [],
            'virus_count' => 0,
        ];
    }

    foreach ($results as $result) {
        // Cast to int to ensure it's not a Carbon object
        $hour = (int) $result->hour;
        $messageCount = (int) $result->message_count;
        
        $hourlyData[$hour]['total'] += $messageCount;
        $hourlyData[$hour]['actions'][$result->action] = ($hourlyData[$hour]['actions'][$result->action] ?? 0) + $messageCount;
        
        if ($result->has_virus) {
            $hourlyData[$hour]['virus_count'] += $messageCount;
        }
    }

    return array_values($hourlyData);
}
    
    public function getActionSummary(string $startDate, string $endDate): array
    {
        $startDateOnly = date('Y-m-d', strtotime($startDate));
        $endDateOnly = date('Y-m-d', strtotime($endDate));
        
        $results = $this->emailStatModel
            ->whereDate('hour', '>=', $startDateOnly)
            ->whereDate('hour', '<=', $endDateOnly)
            ->select(
                'action',
                DB::raw('SUM(count) as total_count'),
                DB::raw('SUM(CASE WHEN has_virus = 1 THEN count ELSE 0 END) as virus_count')
            )
            ->groupBy('action')
            ->orderBy('total_count', 'desc')
            ->get();
        
        $summary = [];
        $totalMessages = 0;
        
        foreach ($results as $result) {
            // Convert to int explicitly to avoid Carbon issues
            $totalCount = (int) $result->total_count;
            $virusCount = (int) $result->virus_count;
            
            $totalMessages += $totalCount;
            $summary[$result->action] = [
                'count' => $totalCount,
                'virus_count' => $virusCount,
                'percentage' => 0,
            ];
        }
        
        // Calculate percentages
        foreach ($summary as $action => &$data) {
            if ($totalMessages > 0) {
                $data['percentage'] = round(($data['count'] / $totalMessages) * 100, 2);
            } else {
                $data['percentage'] = 0;
            }
        }
        
        return [
            'total_messages' => $totalMessages,
            'actions' => $summary,
        ];
    }
}