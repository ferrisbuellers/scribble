<?php

namespace FullStackFool\Scribble;

use Corcel\Model\Post as Corcel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;


class Post extends Corcel
{
    /**
     * Set the database connection.
     *
     * @var string
     */
    protected $connection = 'wordpress';

    /**
     * The next post.
     *
     * @var mixed
     */
    protected $next;

    /**
     * The previous post
     *
     * @var mixed
     */
    protected $previous;


    /**
     * List of associated tags
     *
     * @property string tags
     */
    protected $tags;

    /**
     *  Total share count
     *
     * @property integer shares
     */
    protected $shares;


    /**
     * Return related categories
     *
     * @return mixed
     */
    public function categories()
    {
        return $this->belongsToMany('Corcel\TermTaxonomy', 'term_relationships', 'object_id',
            'term_taxonomy_id')->where('taxonomy', 'category');
    }

    /**
     *  Get the url for the featured image
     *  Default WP sizes are thumbnail, medium, medium_large, large
     *  The height/width of these can be altered from within the WP Admin (settings)
     *
     * @param null|string $size
     *
     * @return mixed
     */
    public function getFeaturedUrl($size = null)
    {
        if ($this->thumbnail) {

            if ($size) {
                $attachmentMetadata = unserialize(
                    $this->thumbnail->attachment->meta()->where('meta_key', '_wp_attachment_metadata')
                                                ->first()->meta_value
                );

                //get the resized filename, otherwise serve the full size url
                if ( ! isset($attachmentMetadata['sizes'][ $size ]['file'])) {
                    return $this->thumbnail->attachment->guid;
                }

                //build the initial url
                $imageUrl = config('wordpress.base_url');
                $imageUrl .= '/wp-content/uploads/';

                // Remove the original image from the url
                $imageParts = explode('/',
                    $this->thumbnail->attachment->meta()->where('meta_key', '_wp_attached_file')
                                                ->first()->meta_value, -1);

                // Rebuild the url with the thumbnail image.
                return $imageUrl . implode('/',
                        array_merge($imageParts, [$attachmentMetadata['sizes'][ $size ]['file']]));
            }

            return $this->thumbnail->attachment->guid;
        }
    }

    /**
     * Get the alt for the featured image
     *
     * @return mixed
     */
    public function getFeaturedAlt()
    {
        if ($this->thumbnail) {
            return $this->thumbnail->attachment->meta->_wp_attachment_image_alt;
        }
    }

    /**
     *  Get the excerpt for the post, limiting the length
     *
     * @param int  $limit
     *
     * @param null $mutators
     *
     * @param null $toReadMore
     *
     * @return string
     */
    public function getExcerpt($limit = 120, $mutators = null, $toReadMore = false)
    {
        $content = $this->post_content;

        if (is_array($mutators)) {
            foreach ($mutators as $mutator) {
                if (strlen($mutator) < $limit) {
                    $limit -= strlen($mutator);
                } else {
                    $limit = 0;
                }
            }
        } elseif ( ! empty($mutators) && strlen($mutators) < $limit) {
            $limit -= strlen($mutators);
        }

        if ($toReadMore) {
            $content = $this->getReadMore($content);
        }

        return str_limit(strip_tags($content), $limit);
    }

    /**
     * Get the formatted published date
     *
     * @param string $format
     *
     * @return mixed
     */
    public function getPublishedDate($format = 'jS F Y')
    {
        return $this->post_date->format($format);
    }

    /**
     *  Get the content and format with <p> tags
     *
     * @return string
     */
    public function getContent()
    {
        return '<p>' . str_replace(PHP_EOL, '</p><p>', $this->post_content) . '</p>';
    }

    /**
     * Get the next post.
     *
     * @param bool $scopeToCat Scope the return to the initial post's category
     * @return mixed
     */
    public function next($scopeToCat = true)
    {
        if ( ! $this->next) {
            $query = $this->where('post_date', '>', $this->post_date)
                          ->published()
                          ->type('post');

            if ($scopeToCat) {
                $query->whereHas('taxonomies', function ($query) {
                    $query->category()->whereHas('term', function ($q2) {
                        $q2->where('name', '=', $this->getMainCategoryAttribute());
                    });
                });
            }

            $this->next = $query->orderBy('post_date', 'asc')->first();
        }

        return $this->next;
    }

    /**
     * Get the previous post.
     *
     * @param bool $scopeToCat Scope the return to the initial post's category
     * @return mixed
     */
    public function previous($scopeToCat = true)
    {
        if ( ! $this->previous) {
            $query = $this->where('post_date', '<', $this->post_date)
                          ->published()
                          ->type('post');
            if ($scopeToCat) {
                $query->whereHas('taxonomies', function ($query) {
                    $query->category()->whereHas('term', function ($q2) {
                        $q2->where('name', '=', $this->getMainCategoryAttribute());
                    });
                });
            }

            $this->previous = $query->orderBy('post_date', 'desc')->first();
        }

        return $this->previous;
    }

    /**
     * Get the most recent posts by type and limit if required
     *
     * @param Builder $builder
     * @param null    $limit
     * @param string  $type
     *
     * @return mixed
     */
    public function scopeRecent(Builder $builder, $limit = null, $type = 'post')
    {
        $builder->published()->type($type)->orderBy('post_date', 'desc');

        if ( ! empty($limit)) {
            $builder->limit($limit);
        }
    }

    /**
     * Get posts by categories
     *
     * @param Builder      $builder
     * @param array|string $slugs The category slugs to filter by
     */
    public function scopeHasCategories(Builder $builder, $slugs)
    {
        $builder->whereHas('taxonomies', function ($query) use ($slugs) {
            $query->category()->whereHas('term', function ($query) use ($slugs) {
                $query->whereIn('slug', $slugs);
            });
        });
    }

    /**
     * Get posts by tags
     *
     * @param Builder      $builder
     * @param array|string $slugs The tag slugs to filter by
     */
    public function scopeHasTags(Builder $builder, $slugs)
    {
        $builder->whereHas('taxonomies', function ($query) use ($slugs) {

            $query->where('taxonomy', '=', 'post_tag')->whereHas('term', function ($query) use ($slugs) {
                $query->whereIn('slug', $slugs);
            });
        });
    }

    /**
     * Get posts by array of search terms applied to post titles
     *
     * @param Builder $builder
     * @param array   $keywords
     */
    public function scopeHasTitleKeywords(Builder $builder, array $keywords)
    {
        foreach ($keywords as $key => $keyword) {
            if ($key == 0) {
                $builder->where('post_title', 'like', '%' . $keyword . '%');
            } else {
                $builder->orWhere('post_title', 'like', '%' . $keyword . '%');
            }
        }
    }

    /**
     * Get posts by month
     *
     * @param Builder $builder
     * @param         $months
     */
    public function scopePublishedMonths(Builder $builder, $months)
    {
        $builder->whereIn(DB::raw('MONTHNAME(post_date)'), $months);
    }
    
    /**
     * Get posts by yearS
     *
     * @param Builder $builder
     * @param $year
     */
    public function scopePublishedYear(Builder $builder, $year)
    {
        $builder->whereIn(DB::raw('YEAR(post_date)'), $year);
    }

    /**
     *  Get the associated tags
     *
     * @return mixed
     */
    public function tags()
    {
        if ( ! $this->tags && isset($this->terms['tag'])) {
            $this->tags = $this->terms['tag'];
        }

        return $this->tags;
    }

    /**
     * Retrieve the total "ShareThis" share count
     *
     * @return mixed
     */
    public function shares()
    {
        if ( ! $this->shares) {
            $this->shares = Cache::remember('post-id-' . $this->ID, 15, function () {

                $curl = curl_init();
                $url = 'http://rest.sharethis.com/v1/count/urlinfo?url=' . (route('blog.show',
                        [$this->post_name]));
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_URL => $url,
                ]);

                $response = curl_exec($curl);

                if ( ! json_decode($response)->total->outbound) {
                    return 0;
                }

                return json_decode($response)->total->outbound;
            });
        }

        return $this->shares;
    }

    /**
     * Get the SEO Yoast meta data for the post
     *
     * @param $suffix
     * @return string
     */
    public function getSeoMeta($suffix = null)
    {
        $meta = '<title>' . $this->title . (! empty($suffix) ? " | $suffix" : '') . '</title>';

        if ($this->meta->_yoast_wpseo_metadesc) {
            $meta .= PHP_EOL . '<meta name="description" content="' . $this->meta->_yoast_wpseo_metadesc . '">';
        } else {
            $meta .= PHP_EOL . '<meta name="description" content="' . $this->getExcerpt(150) . '">';
        }

        return $meta;
    }

    /**
     * Get the months that have post, with counts
     *
     * @param string $type
     * @return mixed
     */
    public function getMonthCounts($type = 'post')
    {
        $raw = $this->select(DB::raw("MONTHNAME(post_date) as month, COUNT(*) as count"))
                    ->published()
                    ->type($type)
                    ->groupBy(DB::raw('MONTH(post_date)'))->get()->toArray();

        return array_map(function ($a) {
            return array_only($a, ['month', 'count']);
        }, $raw);
    }

    /**
     * Overrides default behaviour by instantiating class based on the $attributes->post_type value.
     *
     * By default, this method will always return an instance of the calling class. However if post types have
     * been registered with the Post class using the registerPostType() static method, this will now return an
     * instance of that class instead.
     *
     * If the post type string from $attributes->post_type does not appear in the static $postTypes array,
     * then the class instantiated will be the called class (the default behaviour of this method).
     *
     * @param array $attributes
     * @param null  $connection
     *
     * @return mixed
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        if (is_object($attributes) && isset($attributes->post_type)
            && array_key_exists($attributes->post_type, static::$postTypes)
        ) {
            $class = static::$postTypes[ $attributes->post_type ];
        } elseif (is_array($attributes) && array_key_exists($attributes['post_type'], static::$postTypes)) {
            $class = static::$postTypes[ $attributes['post_type'] ];
        } else {
            $class = get_called_class();
        }

        $model = new $class([]);
        $model->exists = true;

        $model->setRawAttributes((array)$attributes, true);
        $model->setConnection($connection ?: $this->connection);

        return $model;
    }

    /**
     * Gets the posts content up to the read more tag
     *
     * @param $content
     */
    private function getReadMore($content)
    {
        return explode('<!--more-->', $content)[0];
    }
}
