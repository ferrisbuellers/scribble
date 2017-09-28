<?php

namespace FullStackFool\Scribble\Post;

use Corcel\Model\Taxonomy as Corcel;

class Taxonomy extends Corcel
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->connection = config('scribble.connection');
    }

    /**
     * Relationship with Posts model
     *
     * @return Illuminate\Database\Eloquent\Relations
     */
    public function posts()
    {
        return $this->belongsToMany(\FullStackFool\Scribble\Post::class, 'term_relationships', 'term_taxonomy_id', 'object_id')
                    ->published()->type('post');
    }

    /**
     * Relationship with Posts model (with type, order and limit)
     *
     * @param int $limit
     * @return Illuminate\Database\Eloquent\Relations
     */
    public function postSelection($limit = 4)
    {
        return $this->belongsToMany(\FullStackFool\Scribble\Post::class, 'term_relationships', 'term_taxonomy_id', 'object_id')
                    ->published()
                    ->type('post')
                    ->orderBy('post_date_gmt', 'desc')
                    ->limit($limit);
    }

    /**
     * Set taxonomy type to tag.
     *
     * @return Corcel\TermTaxonomyBuilder
     */
    public function tag()
    {
        return $this->where('taxonomy', 'post_tag');
    }
}
