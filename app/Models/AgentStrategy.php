<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AgentStrategy extends Model
{
    /** @use HasFactory<\Database\Factories\AgentStrategyFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_id',
        'name',
        'urls',
        'max_products',
        'min_discount_percentage',
    ];

    protected static function booted(): void
    {
        static::creating(function (AgentStrategy $strategy) {
            if ($strategy->user_id === null && auth()->check()) {
                $strategy->user_id = auth()->id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'urls' => 'array',
            'max_products' => 'integer',
            'min_discount_percentage' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'agent_strategy_tag')
            ->orderBy('name')
            ->withTimestamps();
    }

    /**
     * Scope to only current user.
     */
    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id());
    }

    /**
     * Get allowed hosts from the strategy URLs and the associated store domains.
     */
    public function allowedHosts(): array
    {
        $hosts = [];

        foreach ((array) $this->urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        foreach ((array) $this->store?->domains as $domain) {
            if (is_string($domain) && $domain !== '') {
                $hosts[] = strtolower($domain);
            } elseif (is_array($domain) && isset($domain['domain'])) {
                $hosts[] = strtolower((string) $domain['domain']);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
