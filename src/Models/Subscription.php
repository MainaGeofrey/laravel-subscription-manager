<?php declare(strict_types=1);

namespace Rokde\SubscriptionManager\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Rokde\SubscriptionManager\Models\Concerns\HandlesCancellation;

/**
 * Class Subscription
 * @package Rokde\SubscriptionManager\Models
 *
 * @property int $id
 * @property string $subscribable_type
 * @property int $subscribable_id
 * @property int|null $plan_id
 * @property array $features
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $ends_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $deleted_at
 * @property-read Model|\Eloquent $subscribable
 * @property-read null|Plan $plan
 * @method static Builder|Subscription active()
 * @method static Builder|Subscription cancelled()
 * @method static Builder|Subscription ended()
 * @method static Builder|Subscription notCancelled()
 * @method static Builder|Subscription notOnGracePeriod()
 * @method static Builder|Subscription notOnTrial()
 * @method static Builder|Subscription onGracePeriod()
 * @method static Builder|Subscription onTrial()
 * @method static Builder|Subscription recurring()
 */
class Subscription extends Model
{
    use HandlesCancellation,
        HasFactory,
        SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'features' => 'array',
    ];

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function hasPlan(?Plan $plan = null): bool
    {
        return $plan instanceof Plan
            ? $this->plan_id === $plan->getKey()
            : $this->plan_id !== null;
    }

    /**
     * @param string|\Rokde\SubscriptionManager\Models\Feature $feature
     * @return bool
     */
    public function hasFeature($feature): bool
    {
        if ($feature instanceof Feature) {
            $feature = $feature->code;
        }

        return in_array($feature, $this->features);
    }

    /**
     * How long is a normal period on the subscription
     * default: 1 year
     *
     * @return \Carbon\CarbonInterval
     */
    public function periodLength(): CarbonInterval
    {
        return CarbonInterval::year(1);
    }

    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    public function active(): bool
    {
        return $this->ends_at === null || $this->onGracePeriod();
    }

    public function scopeActive(Builder $query)
    {
        $query->where(function (Builder $query) {
            $query->whereNull('ends_at')
                ->orWhere(function (Builder $query) {
                    $query->onGracePeriod();
                });
        });
    }

    public function recurring(): bool
    {
        return !$this->onTrial() && !$this->cancelled();
    }

    public function scopeRecurring(Builder $query)
    {
        $query->notOnTrial()->noCancelled();
    }

    public function cancelled(): bool
    {
        return $this->ends_at !== null;
    }

    public function scopeCancelled(Builder $query)
    {
        $query->whereNotNull('ends_at');
    }

    public function scopeNotCancelled(Builder $query)
    {
        $query->whereNull('ends_at');
    }

    public function ended(): bool
    {
        return $this->cancelled() && !$this->onGracePeriod();
    }

    public function scopeEnded(Builder $query)
    {
        $query->cancelled()->notOnGracePeriod();
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function scopeOnTrial(Builder $query)
    {
        $query->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', Carbon::now());
    }

    public function scopeNotOnTrial(Builder $query)
    {
        $query->whereNull('trial_ends_at')
            ->orWhere('trial_ends_at', '<=', Carbon::now());
    }

    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    public function scopeOnGracePeriod(Builder $query)
    {
        $query->whereNotNull('ends_at')
            ->where('ends_at', '>', Carbon::now());
    }

    public function scopeNotOnGracePeriod(Builder $query)
    {
        $query->whereNull('ends_at')
            ->orWhere('ends_at', '<=', Carbon::now());
    }
}
