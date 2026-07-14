<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DatePeriodFilter
{
    /**
     * period: today (default) | day | month
     * date: Y-m-d (para day)
     * month: Y-m (para month)
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function apply(Builder $query, Request $request, string $column = 'created_at'): Builder
    {
        $period = strtolower((string) $request->input('period', 'today'));

        if ($period === 'all') {
            return $query;
        }

        if ($period === 'month') {
            $month = (string) $request->input('month', now()->format('Y-m'));
            try {
                $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            } catch (\Throwable) {
                $start = now()->startOfMonth();
            }

            return $query
                ->where($column, '>=', $start->copy()->startOfDay())
                ->where($column, '<=', $start->copy()->endOfMonth()->endOfDay());
        }

        if ($period === 'day') {
            $date = (string) $request->input('date', now()->toDateString());
            try {
                $day = Carbon::parse($date);
            } catch (\Throwable) {
                $day = now();
            }

            return $query->whereDate($column, $day->toDateString());
        }

        // today
        return $query->whereDate($column, now()->toDateString());
    }

    /**
     * @return array{period:string,date:?string,month:?string}
     */
    public static function meta(Request $request): array
    {
        $period = strtolower((string) $request->input('period', 'today'));

        return [
            'period' => in_array($period, ['today', 'day', 'month', 'all'], true) ? $period : 'today',
            'date' => $request->input('date'),
            'month' => $request->input('month'),
        ];
    }
}
