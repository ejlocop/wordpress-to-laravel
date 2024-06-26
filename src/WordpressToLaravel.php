<?php

namespace LeeOvery\WordpressToLaravel;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Item;
use League\Fractal\TransformerAbstract;
use stdClass;

class WordpressToLaravel
{
	/**
	 * @var string
	 */
	protected $hostedEndpoint = 'wp-json/wp/v2/';

	/**
	 * @var string
	 */
	protected $postModel;

	/**
	 * @var string
	 */
	protected $categoryModel;

	/**
	 * @var string
	 */
	protected $authorModel;

	/**
	 * @var TransformerAbstract
	 */
	protected $postTransformer;

	/**
	 * @var TransformerAbstract
	 */
	protected $categoryTransformer;

	/**
	 * @var TransformerAbstract
	 */
	protected $authorTransformer;

	/**
	 * @var TransformerAbstract
	 */
	protected $tagTransformer;

	/**
	 * WordpressToLaravel constructor.
	 *
	 * @param FractalManager $fractalManager
	 * @param GuzzleClient   $client
	 * @param array          $config
	 */
	public function __construct(protected FractalManager $fractalManager, protected GuzzleClient $client, protected array $config)
	{
		$this->setupModels();
		$this->setupTransformers();
	}

	protected function setupModels()
	{
		$this->postModel = $this->config['post_model'] ?? Post::class;
		$this->categoryModel = $this->config['category_model'] ?? Category::class;
		$this->authorModel = $this->config['author_model'] ?? Author::class;
	}

	protected function isWordpressCom()
	{
		return $this->config['is_wordpress_com'];
	}

	protected function setupTransformers()
	{
		$this->postTransformer = Arr::get($this->config, 'transformers.post') ?? PostTransformer::class;
		$this->categoryTransformer = Arr::get($this->config, 'transformers.category') ?? CategoryTransformer::class;
		$this->authorTransformer = Arr::get($this->config, 'transformers.author') ?? AuthorTransformer::class;
		$this->tagTransformer = Arr::get($this->config, 'transformers.tag') ?? TagTransformer::class;
	}

	/**
	 * @param string $postRestBase
	 * @param int    $page
	 * @param int    $perPage
	 * @param bool   $truncate
	 * @param bool   $forceAll
	 */
	public function import($postRestBase, $page = 1, $perPage = 10, $truncate = false, $forceAll = false)
	{
		$this->truncate($truncate)
			 ->fetchPosts($postRestBase, $page, $perPage, $forceAll)
			 ->map(function ($post) {
				 return $this->transformPost($post);
			 })
			 ->each(function ($post) {
				 $this->syncPost($post);
			 });
	}

	/**
	 * Setup the getPosts request
	 *
	 * @param string $postRestBase
	 * @param int    $page
	 * @param int    $perPage
	 * @param bool   $forceAll
	 * @return Collection
	 */
	protected function fetchPosts($postRestBase, $page, $perPage, $forceAll)
	{
		$posts = collect();

		while (true) {
			$wpurl = $this->makeUrl($postRestBase, $page++, $perPage);
			logger($wpurl);
			$stop = collect(
				$this->sendRequest($wpurl)
			)->map(function ($post) use ($posts) {
				$posts->push($post);
			})->isEmpty();

			if (! $forceAll || $stop) {
				break;
			}
		}

		return $posts;
	}

	/**
	 * Send the request
	 *
	 * @param string $url
	 * @param int    $tries
	 * @return array
	 */
	protected function sendRequest($url, $tries = 3)
	{
		$tries--;

		beginning:
		try {
			$results = $this->client->get($url);
		} catch (ConnectException $e) {
			if (! $tries) {
				return [];
			}

			$tries--;

			usleep(100);

			goto beginning;
		} catch (\Exception $e) {
			return [];
		}

		if ($results) {
			return json_decode(
				$results->getBody()
			);
		}

		return [];
	}

	/**
	 * @param string $postRestBase
	 * @param int    $page
	 * @param int    $perPage
	 * @return string
	 */
	protected function makeUrl($postRestBase, $page, $perPage)
	{
		$queryString = sprintf(
			// "%s?_embed=true&filter[orderby]=modified&page=%d&per_page=%d",
			"%s?filter[orderby]=modified&page=%d&per_page=%d",
			$postRestBase, $page, $perPage
		);

		return sprintf(
			'%s%s%s',
			Str::finish($this->config['api_url'], '/'),
			$this->isWordpressCom() ? '' : $this->hostedEndpoint,
			$queryString
		);
	}

	protected function truncate($truncate)
	{
		if ($truncate) {
			($this->postModel)::truncate();
			($this->categoryModel)::truncate();
			($this->authorModel)::truncate();
			DB::table('tags')->truncate();
			DB::table('tagged')->truncate();
		}

		return $this;
	}

	/**
	 * @param stdClass $post
	 * @return array
	 */
	protected function transformPost(stdClass $post)
	{
		return $this->fractalManager->createData($this->createPostResource($post))
			->toArray();
	}

	/**
	 * @param stdClass $post
	 * @return Item
	 */
	private function createPostResource(stdClass $post): Item
	{
		return new Item($post, new $this->postTransformer(
			$this->authorTransformer,
			$this->categoryTransformer,
			$this->tagTransformer
		));
	}

	/**
	 * @param array $data
	 */
	protected function syncPost($data)
	{
		$tagsData = $data['tags'];
		$authorData = $data['author'];
		$categoryData = $data['category'];
		unset($data['tags'], $data['author'], $data['category']);

		if (! $post = ($this->postModel)::where('wp_id', $data['wp_id'])->first()) {
			$post = ($this->postModel)::create($data);
		}

		if ($data['updated_at']->gt($post->updated_at)) {
			$post->update($data);
			event(new PostUpdated($post));
		}

		$post->setTags($tagsData);
		$post->setCategory($categoryData);
		$post->setAuthor($authorData);
		$post->save();

		if ($post->wasRecentlyCreated) {
			event(new PostImported($post));
		}
	}
}
