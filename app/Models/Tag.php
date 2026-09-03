<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property string $name
 * @property int $weight
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
        ];
    }

    public static function booted(): void
    {
        static::deleting(function (Tag $tag) {
            $tag->products()->detach();
        });
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'taggable');
    }

    public function agentStrategies(): BelongsToMany
    {
        return $this->belongsToMany(AgentStrategy::class, 'agent_strategy_tag')
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
}
