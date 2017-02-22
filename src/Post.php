<?php

namespace FullStackFool\Scribble;

use Corcel\Post as Corcel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;


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
                if ( ! isset($attachmentMetadata['sizes'][$size]['file'])) {
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
                        array_merge($imageParts, [$attachmentMetadata['sizes'][$size]['file']]));
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
     * @return string
     */
    public function getExcerpt($limit = 120, $mutators = null)
    {
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

        return str_limit(strip_tags($this->post_content), $limit);
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
        return $this->post_date_gmt->format($format);
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
     * @return mixed
     */
    public function next()
    {
        if ( ! $this->next) {
            $query = $this->where('ID', '>', $this->ID)
                          ->published()
                          ->type('post');

            $query->whereHas('taxonomies', function ($query) {
                $query->category()->whereHas('term', function ($q2) {
                    $q2->where('name', '=', $this->getMainCategoryAttribute());
                });
            });

            $this->next = $query->orderBy('post_date_gmt', 'asc')->first();
        }

        return $this->next;
    }

    /**
     * Get the previous post.
     *
     * @return mixed
     */
    public function previous()
    {
        if ( ! $this->previous) {
            $query = $this->where('ID', '<', $this->ID)
                          ->published()
                          ->type('post');
            $query->whereHas('taxonomies', function ($query) {
                $query->category()->whereHas('term', function ($q2) {
                    $q2->where('name', '=', $this->getMainCategoryAttribute());
                });
            });

            $this->previous = $query->orderBy('post_date_gmt', 'desc')->first();
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
        $builder->published()->type($type)->orderBy('post_date_gmt', 'desc');

        if ( ! empty($limit)) {
            $builder->limit($limit);
        }
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
                    CURLOPT_URL => $url
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
}
