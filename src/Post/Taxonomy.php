<?php

namespace FullStackFool\Post;

use Corcel\TermTaxonomy as Corcel;

class Taxonomy extends Corcel
{
    /**
     * Set the database connection for the model.
     *
     * @var string
     */
    protected $connection = 'wordpress';

    /**
     * Relationship with Posts model
     *
     * @return Illuminate\Database\Eloquent\Relations
     */
    public function posts()
    {
        return $this->belongsToMany(FullStackFool\Post::class, 'term_relationships', 'term_taxonomy_id', 'object_id')
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
        return $this->belongsToMany(FullStackFool\Post::class, 'term_relationships', 'term_taxonomy_id', 'object_id')
                    ->published()
                    ->type('post')
                    ->orderBy('post_date_gmt', 'desc')
                    ->limit($limit);
    }
}