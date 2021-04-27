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
use Rokde\SubscriptionManager\Events\SubscriptionCanceled;
use Rokde\SubscriptionManager\Events\SubscriptionCreated;
use Rokde\SubscriptionManager\Events\SubscriptionDeleted;
use Rokde\SubscriptionManager\Events\SubscriptionPurged;
use Rokde\SubscriptionManager\Events\SubscriptionRestored;
use Rokde\SubscriptionManager\Events\SubscriptionResumed;
use Rokde\SubscriptionManager\Events\SubscriptionUpdated;
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
 * @property string|null $period
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
    use HandlesCancellation;
    use HasFactory;
    use SoftDeletes;

    /**
     * @var string[]|bool
     */
    protected $guarded = [];

    /**
     * @var array
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'features' => 'array',
    ];

    /**
     * @var array
     */
    protected $dispatchesEvents = [
        'cancelled' => SubscriptionCanceled::class,
        'created' => SubscriptionCreated::class,
        'deleted' => SubscriptionDeleted::class,
        'forceDeleted' => SubscriptionPurged::class,
        'restored' => SubscriptionRestored::class,
        'resumed' => SubscriptionResumed::class,
        'updated' => SubscriptionUpdated::class,
    ];

    protected static function booted()
    {
        static::updated(function (Subscription $subscription) {
            //  fire custom events for cancelling or resuming a subscription
            if ($subscription->isDirty('ends_at')) {
                if ($subscription->getAttribute('ends_at') !== null) {
                    $subscription->fireCustomModelEvent('cancelled', 'dispatch');
                } else {
                    $subscription->fireCustomModelEvent('resumed', 'dispatch');
                }
            }
        });
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Does the subscription has a plan assigned
     *
     * @param \Rokde\SubscriptionManager\Models\Plan|null $plan
     * @return bool
     */
    public function hasPlan(?Plan $plan = null): bool
    {
        return $plan instanceof Plan
            ? $this->plan_id === $plan->getKey()
            : $this->plan_id !== null;
    }

    /**
     * Does the subscription has a feature assigned
     *
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
     * default: 1 year; infinite period is 1000 years
     *
     * @return \Carbon\CarbonInterval
     * @throws \Exception
     */
    public function periodLength(): CarbonInterval
    {
        return new CarbonInterval($this->period ?? 'P1000Y');
    }

    /**
     * Is the subscription infinite
     *
     * @return bool
     */
    public function isInfinite(): bool
    {
        return $this->period === null;
    }

    /**
     * Is the subscription valid (active or on trial or on grace period, but not ended)
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->isActive() || $this->isOnTrial() || $this->isOnGracePeriod();
    }

    /**
     * Is the subscription active (not on grace period or not cancelled)
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->ends_at === null || $this->isOnGracePeriod();
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereNull('ends_at')
                ->orWhere(
                    /** @param \Illuminate\Database\Eloquent\Builder|static $query */
                    function (Builder $query) {
                        $query->onGracePeriod();
                    }
                );
        });
    }

    /**
     * Is the subscription recurring (circles) (not infinite, not on trial and not cancelled)
     *
     * @return bool
     */
    public function isRecurring(): bool
    {
        return $this->period !== null && !$this->isOnTrial() && !$this->isCancelled();
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeRecurring(Builder $query): void
    {
        $query->notOnTrial()
            ->noCancelled()
            ->whereNotNull('period');
    }

    /**
     * Is subscription cancelled (end date set)
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->ends_at !== null;
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeCancelled(Builder $query): void
    {
        $query->whereNotNull('ends_at');
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeNotCancelled(Builder $query): void
    {
        $query->whereNull('ends_at');
    }

    /**
     * Is subscription already ended (cancelled and not on grace period)
     *
     * @return bool
     */
    public function isEnded(): bool
    {
        return $this->isCancelled() && !$this->isOnGracePeriod();
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeEnded(Builder $query): void
    {
        $query->cancelled()->notOnGracePeriod();
    }

    /**
     * Is subscription on trial currently
     *
     * @return bool
     */
    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeOnTrial(Builder $query)
    {
        $query->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', Carbon::now()->toDateTimeString());
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeNotOnTrial(Builder $query)
    {
        $query->whereNull('trial_ends_at')
            ->orWhere('trial_ends_at', '<=', Carbon::now()->toDateTimeString());
    }

    /**
     * Is subscription on grace period (end date is set and in future)
     *
     * @return bool
     */
    public function isOnGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeOnGracePeriod(Builder $query)
    {
        $query->whereNotNull('ends_at')
            ->where('ends_at', '>', Carbon::now()->toDateTimeString());
    }

    /**
     * scope for builder
     *
     * @param \Illuminate\Database\Eloquent\Builder|static $query
     */
    public function scopeNotOnGracePeriod(Builder $query)
    {
        $query->whereNull('ends_at')
            ->orWhere('ends_at', '<=', Carbon::now()->toDateTimeString());
    }

    /**
     * Returns a subscription circles collection
     *
     * @return array|\Rokde\SubscriptionManager\Models\SubscriptionCircle[]
     * @throws \Exception
     */
    public function circles(): array
    {
        $circles = [];

        $startDate = $this->created_at->clone();
        $interval = $this->periodLength();
        $hardEndDate = $this->ends_at;

        do {
            $endDate = $startDate->clone()->add($interval);
            if ($hardEndDate !== null && $endDate->greaterThan($hardEndDate)) {
                $endDate = $hardEndDate->clone();
            }

            $circles[] = new SubscriptionCircle($this, $startDate->clone(), $endDate->clone(), count($circles) + 1);

            //  prepare for next run
            $startDate = $endDate->clone();
        } while (
            ($hardEndDate === null && $endDate->isPast())
            || ($hardEndDate && $hardEndDate->greaterThan($endDate))
        );

        return $circles;
    }
}
