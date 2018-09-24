<?php
/**
 * @package     PublishPress\Notifications
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2018 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */

namespace PublishPress\Notifications\Workflow\Step\Receiver;

class Author extends Simple_Checkbox implements Receiver_Interface
{
    const META_KEY = '_psppno_toauthor';

    const META_VALUE = 'author';

    protected $option = 'receiver_author';

    /**
     * The constructor
     */
    public function __construct()
    {
        $this->name        = 'author';
        $this->label       = __('Authors of the content', 'publishpress');
        $this->option_name = 'receiver_author';

        parent::__construct();
    }

    /**
     * Filters the list of receivers for the workflow. Returns the list of IDs.
     *
     * @param array   $receivers
     * @param WP_Post $workflow
     * @param array   $args
     * @return array
     */
    public function filter_workflow_receivers($receivers, $workflow, $args)
    {
        // If checked, add the authors to the list of receivers
        if ($this->is_selected($workflow->ID)) {
            $receivers[] = (int)$args['post']->post_author;

            /**
             * Filters the list of receivers, but triggers only when the authors are selected.
             *
             * @param array   $receivers
             * @param WP_Post $workflow
             * @param array   $args
             */
            $receivers = apply_filters('publishpress_notif_workflow_receiver_post_authors', $receivers, $workflow, $args);
        }

        return $receivers;
    }

    /**
     * Add the respective value to the column in the workflow list
     *
     * @param array $values
     * @param int   $post_id
     *
     * @return array
     */
    public function filter_receivers_column_value($values, $post_id)
    {
        if ($this->is_selected($post_id)) {
            $values[] = __('Authors', 'publishpress');
        }

        return $values;
    }
}
