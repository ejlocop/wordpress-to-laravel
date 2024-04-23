<?php
/**
 * Created by PhpStorm.
 * User: leeovery
 * Date: 20/12/2016
 * Time: 12:16
 */

namespace LeeOvery\WordpressToLaravel;

class PostUpdated
{

	/**
	 * PostUpdated constructor.
	 *
	 * @param $post
	 */
	public function __construct(public Post $post)
	{
	}
}