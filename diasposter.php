<?php
/**
 * Plugin Name: Diasposter
 * Plugin URI: https://github.com/meitar/diasposter/#readme
 * Description: Automatically crossposts to your Diaspora* stream when you publish a post on your WordPress blog.
 * Version: 0.1.8
 * Author: Meitar Moscovitz
 * Author URI: http://Cyberbusking.org/
 * Text Domain: diasposter
 * Domain Path: /languages
 */

class Diasposter {
    private $diaspora; //< API manipulation wrapper.
    private $prefix = 'diasposter'; //< String to prefix plugin options, settings, etc.

    public function __construct () {
        add_action('plugins_loaded', array($this, 'registerL10n'));
        add_action('init', array($this, 'setSyncSchedules'));
        add_action('admin_init', array($this, 'registerSettings'));
        add_action('admin_menu', array($this, 'registerAdminMenu'));
        add_action('admin_enqueue_scripts', array($this, 'registerAdminScripts'));
        add_action('admin_head', array($this, 'registerContextualHelp'));
        add_action('admin_notices', array($this, 'showAdminNotices'));
        add_action('add_meta_boxes', array($this, 'addMetaBox'));
        add_action('save_post', array($this, 'savePost'));
        add_action('before_delete_post', array($this, 'removePost'));
        add_action('delete_comment', array($this, 'deleteComment'));
        // run late, so themes have a chance to register support
        // TODO: Add Post Format support.
        //add_action('after_setup_theme', array($this, 'registerThemeSupport'), 700);

        add_action($this->prefix . '_sync_comment_content', array($this, 'syncCommentContent'));

        add_filter('post_row_actions', array($this, 'addPostRowAction'), 10, 2);
        add_filter('plugin_row_meta', array($this, 'addPluginRowMeta'), 10, 2);
        add_filter('get_avatar', array($this, 'getAvatar'), 10, 5);
        add_filter('syn_add_links', array($this, 'addSyndicationLinks'));

        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        $options = get_option($this->prefix . '_settings');
        if (empty($options['user_accounts'])) {
            add_action('admin_notices', array($this, 'showMissingConfigNotice'));
        } else {
            if (!class_exists('Diaspora_Connection')) {
                require_once 'lib/Diaspora_Connection.php';
            }
            $this->diaspora = new Diaspora_Connection($options['user_accounts'][0], $this->decrypt($options['passwords'][0]));
            if (!empty($options['debug'])) {
                $this->diaspora->setDebugLog(ABSPATH . '/wp-content/debug.log');
            }
        }
    }

    /**
     * Implements rel-syndication for POSSE as expected by the
     * Syndication Links plugin for Wordpress.
     *
     * @see https://wordpress.org/plugins/syndication-links/
     * @see http://indiewebcamp.com/rel-syndication
     */
    public function addSyndicationLinks ($urls) {
        $urls[] = $this->getSyndicatedAddress(get_the_ID());
        return $urls;
    }

    public function showMissingConfigNotice () {
        $screen = get_current_screen();
        if ($screen->base === 'plugins') {
?>
<div class="updated">
    <p><a href="<?php print admin_url('options-general.php?page=' . $this->prefix . '_settings');?>" class="button"><?php esc_html_e('Connect to Diaspora*', 'diasposter');?></a> &mdash; <?php esc_html_e('Almost done! Connect your blog to Diaspora* to begin crossposting with Diasposter.', 'diasposter');?></p>
</div>
<?php
        }
    }

    private function showError ($msg) {
?>
<div class="error">
    <p><?php print esc_html($msg);?></p>
</div>
<?php
    }

    private function showNotice ($msg) {
?>
<div class="updated">
    <p><?php print $msg; // No escaping because we want links, so be careful. ?></p>
</div>
<?php
    }

    private function showDonationAppeal () {
?>
<div class="donation-appeal">
    <p style="text-align: center; font-size: larger; width: 70%; margin: 0 auto;"><?php print sprintf(
esc_html__('Diasposter is provided as free software, but sadly grocery stores do not offer free food. If you like this plugin, please consider %1$s to its %2$s. &hearts; Thank you!', 'diasposter'),
'<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=meitarm%40gmail%2ecom&lc=US&amp;item_name=Diasposter%20WordPress%20Plugin&amp;item_number=diasposter&amp;currency_code=USD&amp;bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted">' . esc_html__('making a donation', 'diasposter') . '</a>',
'<a href="http://Cyberbusking.org/">' . esc_html__('houseless, jobless, nomadic developer', 'diasposter') . '</a>'
);?></p>
</div>
<?php
    }

    public function registerThemeSupport () {
        add_theme_support(
            'post-formats',
            $this->diffThemeSupport(array(
                'link',
                'image',
                'quote',
                'video',
                'audio',
                'chat'
            ), 'post-formats')
        );
    }

    /**
     * Returns the difference between a requested and existing theme support for a feature.
     *
     * @param array $new_array The options of a feature to query.
     * @param string $feature The feature to query.
     * @return array The difference, each element as an argument to original add_theme_support() call.
     */
    private function diffThemeSupport ($new_array, $feature) {
        $x = get_theme_support($feature);
        if (is_bool($x)) { $x = array(); }
        $y = (empty($x)) ? array() : $x[0];
        return array_merge($y, array_diff($new_array, $y));
    }

    public function registerL10n () {
        load_plugin_textdomain('diasposter', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function registerSettings () {
        register_setting(
            $this->prefix . '_settings',
            $this->prefix . '_settings',
            array($this, 'validateSettings')
        );
    }

    public function registerContextualHelp () {
        $screen = get_current_screen();
        if ($screen->id !== 'post') { return; }
        $html = '<p>' . esc_html__('You can automatically copy this post to your Diaspora* stream:', 'diasposter') . '</p>'
        . '<ol>'
        . '<li>' . sprintf(
            esc_html__('Compose your post for WordPress as you normally would, with the appropriate %sPost Format%s.', 'diasposter'),
            '<a href="#formatdiv">', '</a>'
            ) . '</li>'
        . '<li>' . sprintf(
            esc_html__('In %sthe Diasposter box%s, ensure the "Send this post to Diaspora*?" option is set to "Yes." (You can set it to "No" if you do not want to copy this post to Diaspora*.)', 'diasposter'),
            '<a href="#diasposter-meta-box">', '</a>'
            ) . '</li>'
        . '<li>' . esc_html__('If you have more than one Diaspora* account, choose the one you want to send this post to from the "Send to my Diaspora* stream" list.', 'diasposter') . '</li>'
        . '</ol>'
        . '<p>' . esc_html__('When you are done, click "Publish", and Diasposter will send your post to the Diaspora* account you chose.', 'diasposter') . '</p>'
        . '<p>' . esc_html__('Note that Diaspora does not allow you to edit the post after you have published it, so be sure you have fully completed your post and have chosen the appropriate Post Format before you publish your post.', 'diasposter') . '</p>';
        ob_start();
        $this->showDonationAppeal();
        $x = ob_get_contents();
        ob_end_clean();
        $html .= $x;
        $screen->add_help_tab(array(
            'id' => $this->prefix . '-' . $screen->base . '-help',
            'title' => __('Crossposting to Diaspora*', 'diasposter'),
            'content' => $html
        ));

        $x = esc_html__('Diasposter:', 'diasposter');
        $y = esc_html__('Diasposter support forum', 'diasposter');
        $z = esc_html__('Donate to Diasposter', 'diasposter');
        $sidebar = <<<END_HTML
<p><strong>$x</strong></p>
<p><a href="https://wordpress.org/support/plugin/diasposter" target="_blank">$y</a></p>
<p><a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=meitarm%40gmail%2ecom&lc=US&item_name=Diasposter%20WordPress%20Plugin&item_number=diasposter&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted" target="_blank">&hearts; $z &hearts;</a></p>
END_HTML;
        $screen->set_help_sidebar($screen->get_help_sidebar() . $sidebar);
    }

    private function getSyndicatedAddress ($post_id) {
        $base_hostname = get_post_meta($post_id, 'diaspora_host', true);
        $id = get_post_meta($post_id, 'diaspora_post_id', true);
        return "http://{$base_hostname}/posts/{$id}";
    }

    public function addPostRowAction ($actions, $post) {
        $id = get_post_meta($post->ID, 'diaspora_post_id', true);
        if ($id) {
            $actions['view_on_diaspora'] = '<a href="' . $this->getSyndicatedAddress($post->ID) . '">' . esc_html__('View post on Diaspora*', 'diasposter') . '</a>';
        }
        return $actions;
    }

    public function setSyncSchedules () {
        if (!$this->isConnectedToService()) { return; }
        $accts_to_sync = $this->getDiasporaAccountsToSync();
        // If we are being asked to sync, set up an hourly schedule for that.
        if (!empty($accts_to_sync)) {
            foreach ($accts_to_sync as $x) {
                if (!wp_get_schedule($this->prefix . '_sync_comment_content', array($x))) {
                    wp_schedule_event(time(), 'hourly', $this->prefix . '_sync_comment_content', array($x));
                }
            }
        }
        // For any accounts we know of but aren't being asked to sync,
        $known_accts = array();
        $users_accts = $this->getDiasporaAccounts();
        if ($users_accts) {
            foreach ($users_accts as $acct) {
                $known_accts[] = $acct;
            }
        }
        $to_unschedule = array_diff($known_accts, $accts_to_sync);
        foreach ($to_unschedule as $x) {
            // check to see if there's a scheduled event to sync it, and,
            // if so, unschedule it.
            wp_unschedule_event(
                wp_next_scheduled($this->prefix . '_sync_comment_content', array($x)),
                $this->prefix . '_sync_comment_content',
                array($x)
            );
        }
    }

    public function deactivate () {
        $accts_to_sync = $this->getDiasporaAccountsToSync();
        if (!empty($accts_to_sync)) {
            foreach ($accts_to_sync as $acct) {
                wp_clear_scheduled_hook($this->prefix . '_sync_comment_content', array($acct));
            }
        }
    }

    /**
     * There's a frustrating bug in PHP? In WordPress? That causes get_posts()
     * to always return an empty array when run in Cron if the PHP version is
     * less than 5.4-ish. Check for that and workaround if necessary.
     *
     * @see https://wordpress.org/support/topic/get_posts-returns-no-results-when-run-via-cron
     */
    private function crosspostExists ($id) {
        // If we're running on PHP >= 5.4, use WordPress's built-in function.
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            return get_posts(array(
                'meta_key' => 'diaspora_post_id',
                'meta_value' => $id,
                'fields' => 'ids'
            ));
        }
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key='%s' AND meta_value='%s'
            ",
            'diaspora_post_id',
            $id
        ));
    }


    public function syncCommentContent ($diaspora_handle) {
        $this->diaspora->logIn();
        $notifications = $this->diaspora->getNotifications('comment_on_post');
        foreach ($notifications as $n) {
            // check to see if we have a post with that Diaspora post ID.
            if ($post_ids = $this->crosspostExists($n->comment_on_post->target_id)) {
                $post_id = array_pop($post_ids);
                $wp_comments = get_comments(array('post_id' => $post_id));
                $d_comments = $this->diaspora->getComments($n->comment_on_post->target_id);
                foreach ($d_comments as $c) {
                    // see if we have a comment with the remote comment GUID.
                    $x = get_comments(array(
                        'post_id' => $post_id,
                        'meta_key' => 'diaspora_comment_guid',
                        'meta_value' => $c->guid
                    ));
                    if (empty($x)) {
                        // If the WP post has no such comment, insert a new comment in the database
                        // with the content of the Diaspora comment.
                        require_once 'lib/Parsedown.php';
                        $Parsedown = new Parsedown();
                        $new_comment_data = array(
                            'comment_post_ID' => $post_id,
                            //'comment_type' => 'diaspora', // this custom comment type breaks some themes
                            'comment_type' => '', // use regular comment type for now
                            'comment_content' => $Parsedown->text($c->text),
                            'comment_author' => $c->author->name,
                            'comment_author_email' => $c->author->diaspora_id,
                            'comment_author_url' => $this->diaspora->getPodURL() . '/people/' . $c->author->guid,
                            // The following only seem to work when the comment
                            // is inserted with wp_insert_comment(). Apparently,
                            // it seems like wp_new_comment() won't support these.
                            'comment_author_IP' => gethostbyname(parse_url($this->diaspora->getPodURL(), PHP_URL_HOST)),
                            'comment_date' => date_i18n('Y-m-d H:i:s', strtotime($c->created_at)),
                            'comment_agent' => ucfirst($this->prefix)
                        );

                        // There seems to be some issue with wp_allow_comment() where the second
                        // time it is called during a WP-Cron, script execution ends. So I guess
                        // we need to check for comment approval manually?
                        $new_comment_data['comment_approved'] = $this->allowComment($new_comment_data);

                        if ($cid = wp_insert_comment($new_comment_data)) {
                            update_comment_meta($cid, 'diaspora_comment_guid', $c->guid);
                            update_comment_meta($cid, 'diaspora_comment_id', $c->id);
                            update_comment_meta($cid, 'diaspora_avatar', $c->author->avatar);
                            // mimic wp_new_comment()'s notification handling
                            $this->commentNotify($cid, $new_comment_data['comment_approved']);
                        }
                    }
                }
            }
        }
    }

    private function allowComment ($new_comment_data) {
        $approved = (check_comment(
            $new_comment_data['comment_author'],
            $new_comment_data['comment_author_email'],
            $new_comment_data['comment_author_url'],
            $new_comment_data['comment_content'],
            $new_comment_data['comment_author_IP'],
            $new_comment_data['comment_agent'],
            $new_comment_data['comment_type']
        )) ? 1 : 0;
        $approved = (wp_blacklist_check(
            $new_comment_data['comment_author'],
            $new_comment_data['comment_author_email'],
            $new_comment_data['comment_author_url'],
            $new_comment_data['comment_content'],
            $new_comment_data['comment_author_IP'],
            $new_comment_data['comment_agent']
        )) ? 'spam' : $approved;
        return apply_filters('pre_comment_approved', $approved, $new_comment_data);
    }

    private function commentNotify ($cid, $approved) {
        if ('spam' !== $approved) {
            if ('0' == $approved) {
                wp_notify_moderator($cid);
            }
            if (get_option('comments_notify') && $approved) {
                wp_notify_postauthor($cid);
            }
        }
    }

    public function getAvatar ($avatar, $id_or_email, $size, $default, $alt) {
        if (is_object($id_or_email)) {
            $d_avatar = get_comment_meta($id_or_email->comment_ID, 'diaspora_avatar', true);
            if ($d_avatar) {
                // TODO: Choose appropriate avatar based on $size
                $avatar = '<img alt="' . $size . '" src="' . $d_avatar->large . '" class="avatar avatar-' . $size . ' photo" height="' . $size . '" width="' . $size . '" />';
            }
        }
        return $avatar;
    }

    private function getDiasporaAccounts () {
        $options = get_option($this->prefix . '_settings');
        return (empty($options['user_accounts'])) ? array() : $options['user_accounts'];
    }
    private function getDiasporaAccountsToSync () {
        $options = get_option($this->prefix . '_settings');
        return (empty($options['sync_comment_content'])) ? array() : $options['sync_comment_content'];
    }

    public function addPluginRowMeta ($links, $file) {
        if (false !== strpos($file, basename(__FILE__))) {
            $new_links = array(
                '&hearts; <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=meitarm%40gmail%2ecom&lc=US&amp;item_name=Diasposter%20WordPress%20Plugin&amp;item_number=diasposter&amp;currency_code=USD&amp;bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted">' . esc_html__('Donate to Diasposter', 'diasposter') . '</a> &hearts;',
                '<a href="https://wordpress.org/support/plugin/diasposter/">' . esc_html__('Diasposter support forum', 'diasposter') . '</a>'
            );
            $links = array_merge($links, $new_links);
        }
        return $links;
    }

    private function isPostCrosspostable ($post_id) {
        $options = get_option($this->prefix . '_settings');
        $crosspostable = true;

        // Do not crosspost if this post is excluded by a certain category.
        if (isset($options['exclude_categories']) && in_category($options['exclude_categories'], $post_id)) {
            $crosspostable = false;
        }

        // Do not crosspost if this specific post was excluded.
        if ('N' === get_post_meta($post_id, $this->prefix . '_crosspost', true)) {
            $crosspostable = false;
        }

        // Do not crosspost if this post already has a diaspora ID
        // (meaning it was previously published and can't be edited)
        $id = get_post_meta($post_id, 'diaspora_post_id');
        if (!empty($id)) {
            $crosspostable = false;
        }

        // Do not crosspost if this post is not also in the 'published' state
        // because Diaspora* cannot handle post edits post-publication.
        if ('publish' !== get_post_status($post_id)) {
            $crosspostable = false;
        }

        // Do not crosspost if this post is password-protected, since
        // that is not a feature Diaspora* supports
        // TODO: Consider if password-protected posts should be optionally
        //       crossposted if they are being sent to to specific aspects?
        if ('' !== get_post_field('post_password', $post_id)) {
            $crosspostable = false;
        }

        return $crosspostable;
    }

    /**
     * Ensures that the supplied aspect ID sharing setting is sensible.
     *
     * @param array $aspect_ids An array of supplied aspect IDs.
     * @return array An array containing only aspect IDs that are valid together.
     */
    private function validateAspectIds ($aspect_ids) {
        $a = false;
        // "Public" and "All Aspects" are exclusive of any other value.
        if (in_array('all_aspects', $aspect_ids, true)) {
            $a = array('all_aspects');
        } else if (in_array('public', $aspect_ids, true)) {
            $a = array('public');
        } else {
            // Any other value must be a numeric string.
            $x = array();
            foreach ($aspect_ids as $id) {
                if (is_string($id) && preg_match('/[0-9]+/', $id)) {
                    $x[] = $id;
                }
            }
            if (!empty($x)) {
                $a = $x;
            }
        }
        return $a;
    }

    /**
     * Helper function to cut down on code duplication.
     *
     * @see #savePost
     */
    private function updatePostSimpleOption ($post_id, $opt) {
        if (isset($_POST[$this->prefix . "_$opt"])) {
            update_post_meta($post_id, $this->prefix . "_$opt", 1);
        } else {
            update_post_meta($post_id, $this->prefix . "_$opt", 0);
        }
    }

    /**
     * Translates WordPress post content to an appropriate Diaspora*
     * post representation in Markdown.
     */
    private function prepareBody ($post_id) {
        $post_body = apply_filters('the_content', get_post_field('post_content', $post_id));
        $post_excerpt = get_post_field('post_excerpt', $post_id);
        // Mimic wp_trim_excerpt() without The Loop.
        if (empty($post_excerpt)) {
            $text = $post_body;
            $text = strip_shortcodes($text); 
            $text = apply_filters('the_content', $text);
            $text = str_replace(']]>', ']]&gt;', $text);
            $text = wp_trim_words($text);
            $post_excerpt = $text;
        }

        $post_body = $this->prepareBodyByPostFormat($post_id);

        if (!class_exists('HTML_To_Markdown')) {
            require_once 'lib/HTML_To_Markdown.php';
        }
        $e = $this->getUseExcerpt($post_id); // Use excerpt?
        $markdown = ($e)
            ? new HTML_To_Markdown($post_excerpt)
            : new HTML_To_Markdown($post_body);
        $diaspora_body = $markdown->output();

        // add custom footer
        $options = get_option($this->prefix . '_settings');
        if (!empty($options['additional_markup'])) {
            $diaspora_body .= "\n\n" . $this->replacePlaceholders($options['additional_markup'], $post_id);
        }

        // add tags to end of post
        if (empty($options['exclude_tags'])) {
            $tags = array();
            if ($t = get_the_tags($post_id)) {
                foreach ($t as $tag) {
                    $tags[] = $tag->slug;
                }
                $tags_line = '#' . trim(implode(' #', $tags));
                $diaspora_body .= "\n\n$tags_line";
            }
        }
        if (isset($options['additional_tags'])) {
            $arr = array_map('trim', $options['additional_tags']);
            $diaspora_body .= (empty($tags_line)) ? "\n\n" : ' ';
            foreach ($arr as $tag) {
                if (!empty($tag)) {
                    $t = strtr($tag, ' #', '- ');
                    $diaspora_body .= "#$t ";
                }
            }
        }

        return $diaspora_body = apply_filters($this->prefix . '_prepared_post', $diaspora_body);
    }

    private function prepareBodyByPostFormat ($post_id) {
        $post_title = apply_filters('the_title', get_post_field('post_title', $post_id));
        $post_body = apply_filters('the_content', get_post_field('post_content', $post_id));
        switch (get_post_format($post_id)) {
            // If an image/gallery, strip the <img> tags.
            // TODO: Detect which images failed to upload,
            //       so that we only strip the others?
            //       (Upload happens during prepareAdditionalData() method)
            case 'image':
            case 'gallery':
                $post_body = $this->strip_only($post_body, 'img');
                break;
            case 'video':
                // TODO
                break;
            case 'audio':
                // TODO
                break;
            case 'quote':
                // TODO: Is there anything special that needs to happen for quotes?
                //       Blockquotes are already formatted pretty nicely. How can
                //       they be made to look even better?
                break;
            case 'link':
                $href = $this->extractByRegex('/<a\s.*?href=["\'](.*?)["\'].*?>/', $post_body, 1);
                $text = $this->extractByRegex('/<a\s.*?>(.*?)<\/a>/', $post_body, 1);
                $post_title = (empty($post_title)) ? strip_tags($text) : $post_title;
                $post_title = '<a href="' . $href . '">' . $post_title . '</a>';
                break;
            case 'standard':
            case 'aside':
            default:
                // do nothing, leave post body as-is
                break;
        }

        // mark up title at start of post
        if (!empty($post_title)) {
            $post_body = "<h1>$post_title</h1>\n\n" . $post_body;
        }

        return $post_body;
    }

    private function prepareAdditionalData ($post_id) {
        $additional_data = array();
        if (get_post_meta($post_id, $this->prefix . '_use_geo', true)) {
            if ($geo = $this->getPostGeo($post_id)) {
                if ($geo['address']) {
                    $additional_data['location_address'] = $geo['address'];
                }
                $additional_data['location_coords'] = "{$geo['latitude']},{$geo['longitude']}";
            }
        }
        $services = array();
        $srvc_opts = array('send_twitter', 'send_tumblr', 'send_wordpress', 'send_facebook');
        foreach ($srvc_opts as $opt) {
            if (isset($_POST[$this->prefix . "_$opt"])) {
                $x = explode('_', $opt);
                $services[] = array_pop($x);
            }
        }
        $services = apply_filters($this->prefix . '_services_array', $services);
        if (!empty($services)) {
            $additional_data['services'] = $services;
        }

        // A "Featured Image" (post thumbnail) will always be the first image.
        if ($thumb_id = get_post_thumbnail_id($post_id)) {
            if ($photo_id = $this->addPhotoData(get_attached_file($thumb_id))) {
                $additional_data['photos'] = $photo_id;
            }
        }
        switch (get_post_format($post_id)) {
            // Selecting "Image" or "Gallery" as the post's Post Format
            // will also directly upload any other images in the post.
            case 'image':
            case 'gallery':
                $photo_ids = array();
                foreach ($this->getImgSrcs(get_post_field('post_content', $post_id)) as $url) {
                    if ($this_id = $this->addPhotoData($url)) {
                        $photo_ids[] = $this_id;
                    }
                }
                if (empty($additional_data['photos'])) {
                    $additional_data['photos'] = $photo_ids;
                } else {
                    if (!is_array($additional_data['photos'])) {
                        $additional_data['photos'] = array($additional_data['photos']);
                    }
                    $additional_data['photos'] += $photo_ids;
                }
                break;
        }
        return $additional_data;
    }

    private function getImgSrcs ($html) {
        $r = array();
        if (preg_match_all('/<img (.+?)>/', $html, $m)) {
            foreach ($m[1] as $match) {
                foreach (wp_kses_hair($match, array('http', 'https')) as $attr) {
                    $img[$attr['name']] = $attr['value'];
                }
                $r[] = $img['src'];
            }
        }
        return $r;
    }

    private function addPhotoData ($file_or_url) {
        $resp = $this->diaspora->postPhoto($file_or_url);
        if ($resp->success) {
            return "{$resp->data->photo->id}";
        } else {
            return false;
        }
    }

    public function savePost ($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!$this->isConnectedToService()) { return; }

        // Only crosspost regular posts unless asked to cross-post other types.
        $options = get_option($this->prefix . '_settings');
        $post_types = array('post');
        if (!empty($options['post_types'])) {
            $post_types = array_merge($post_types, $options['post_types']);
        }
        $post_types = apply_filters($this->prefix . '_save_post_types', $post_types);
        if (!in_array(get_post_type($post_id), $post_types)) {
            return;
        }

        if (!empty($_POST)) {
            $this->updatePostSimpleOption($post_id, 'send_facebook');
            $this->updatePostSimpleOption($post_id, 'send_tumblr');
            $this->updatePostSimpleOption($post_id, 'send_twitter');
            $this->updatePostSimpleOption($post_id, 'send_wordpress');
            $this->updatePostSimpleOption($post_id, 'use_excerpt');
            $this->updatePostSimpleOption($post_id, 'use_geo');
        }

        if (isset($_POST['diaspora_aspect_ids'])) {
            update_post_meta($post_id, 'diaspora_aspect_ids', $this->validateAspectIds($_POST['diaspora_aspect_ids']));
        }

        if (isset($_POST[$this->prefix . '_crosspost']) && 'N' === $_POST[$this->prefix . '_crosspost']) {
            update_post_meta($post_id, $this->prefix . '_crosspost', 'N'); // 'N' means "no"
            return;
        } else {
            delete_post_meta($post_id, $this->prefix . '_crosspost', 'N');
        }

        // Prepare for Diaspora
        if (!$this->isPostCrosspostable($post_id)) { return false; }
        $this->diaspora->logIn();

        $additional_data = $this->prepareAdditionalData($post_id);
        $diaspora_body = $this->prepareBody($post_id);

        // Crosspost to Diaspora
        $id = $this->diaspora->postStatusMessage(
            $diaspora_body,
            get_post_meta($post_id, 'diaspora_aspect_ids', true),
            $additional_data
        );
        if ($id) {
            // Crosspost successful, delete temporary metadata
            delete_post_meta($post_id, $this->prefix . '_send_facebook');
            delete_post_meta($post_id, $this->prefix . '_send_tumblr');
            delete_post_meta($post_id, $this->prefix . '_send_twitter');
            delete_post_meta($post_id, $this->prefix . '_send_wordpress');
            delete_post_meta($post_id, $this->prefix . '_use_excerpt');
            delete_post_meta($post_id, $this->prefix . '_use_geo');
            $parts = explode('@', $this->diaspora->getDiasporaID());
            $host = array_pop($parts);
            update_post_meta($post_id, 'diaspora_host', $host);
            update_post_meta($post_id, 'diaspora_post_id', $id);
            $this->addAdminNotices(
                esc_html__('Post crossposted.', 'diasposter') . ' <a href="' . $this->getSyndicatedAddress($post_id) . '">' . esc_html__('View post on Diaspora*', 'diasposter') . '</a>'
            );
        }
    }

    private function captureDebugOf ($var) {
        ob_start();
        var_dump($var);
        $str = ob_get_contents();
        ob_end_clean();
        return $str;
    }

    private function maybeCaptureDebugOf ($var) {
        $msg = '';
        $options = get_option($this->prefix . '_settings');
        if (isset($options['debug'])) {
            $msg .= esc_html__('Debug output:', 'diasposter');
            $msg .= '<pre>' . $this->captureDebugOf($var) . '</pre>';
        }
        return $msg;
    }

    private function addAdminNotices ($msgs) {
        if (is_string($msgs)) { $msgs = array($msgs); }
        $notices = get_option('_' . $this->prefix . '_admin_notices');
        if (empty($notices)) {
            $notices = array();
        }
        $notices = array_merge($notices, $msgs);
        update_option('_' . $this->prefix . '_admin_notices', $notices);
    }

    public function showAdminNotices () {
        $notices = get_option('_' . $this->prefix . '_admin_notices');
        if ($notices) {
            foreach ($notices as $msg) {
                $this->showNotice($msg);
            }
            delete_option('_' . $this->prefix . '_admin_notices');
        }
    }

    private function replacePlaceholders ($str, $post_id) {
        $placeholders = array(
            '%permalink%',
            '%the_title%',
            '%blog_url%',
            '%blog_name%'
        );
        foreach ($placeholders as $x) {
            if (0 === strpos($x, '%blog_')) {
                $arg = substr($x, 6, -1);
                $str = str_replace($x, get_bloginfo($arg), $str);
            } else {
                $func = 'get_' . substr($x, 1, -1);
                $valid_funcs = array(
                    'get_permalink',
                    'get_the_title'
                );
                if (in_array($func, $valid_funcs, true)) {
                    $str = str_replace($x, call_user_func($func, $post_id), $str);
                }
            }
        }
        return $str;
    }

    /**
     * Extracts a given string from another string according to a regular expression.
     *
     * @param string $pattern The PCRE-compatible regular expression.
     * @param string $str The source from which to extract text matching the $pattern.
     * @param int $group If the regex uses capture groups, the number of the capture group to return.
     * @return string The matched text.
     */
    private function extractByRegex ($pattern, $str, $group = 0) {
        $matches = array();
        $x = preg_match($pattern, $str, $matches);
        return (!empty($matches[$group])) ? $matches[$group] : $x;
    }

    private function getUseExcerpt ($post_id) {
        $x = get_post_meta($post_id, $this->prefix . '_use_excerpt', true);
        if ('' === $x) {
            $options = get_option($this->prefix . '_settings');
            $x = (isset($options['use_excerpt'])) ? $options['use_excerpt'] : 0;
        }
        return intval($x);
    }

    private function getUseGeoData ($post_id) {
        $x = get_post_meta($post_id, $this->prefix . '_use_geo', true);
        if ('' === $x) {
            $options = get_option($this->prefix . '_settings');
            $x = (isset($options['use_geo'])) ? $options['use_geo'] : 0;
        }
        return intval($x);
    }
    private function getPostGeo ($post_id) {
        $geo = false;
        $x = get_post_meta($post_id, 'geo_public', true);
        if ('' === $x || 1 == $x) {
            $geo = array(
                'latitude' => get_post_meta($post_id, 'geo_latitude', true),
                'longitude' => get_post_meta($post_id, 'geo_longitude', true),
                'address' => get_post_meta($post_id, 'geo_address', true),
            );
        }
        return $geo;
    }

    private function getPostAspects ($post_id) {
        $a = get_post_meta($post_id, 'diaspora_aspect_ids', true);
        if (empty($a)) { $a = array('all_aspects'); }
        return $a;
    }

    public function removePost ($post_id) {
        $id = get_post_meta($post_id, 'diaspora_post_id', true);
        $this->diaspora->logIn();
        $this->diaspora->deletePost($id);
    }

    public function deleteComment ($comment_id) {
        $id = get_comment_meta($comment_id, 'diaspora_comment_id', true);
        $this->diaspora->logIn();
        $this->diaspora->deleteComment($id);
    }

    /**
     * @param array $input An array of of our unsanitized options.
     * @return array An array of sanitized options.
     */
    public function validateSettings ($input) {
        $safe_input = array();
        foreach ($input as $k => $v) {
            switch ($k) {
                case 'user_accounts':
                    $safe_input[$k] = array();
                    foreach ($v as $x) {
                        if (empty($x)) {
                            $errmsg = __('User account cannot be empty.', 'diasposter');
                            add_settings_error($this->prefix . '_settings', 'empty-user-account', $errmsg);
                        }
                        $safe_input[$k][] = sanitize_text_field($x);
                    }
                break;
                case 'passwords':
                    $safe_input[$k] = array();
                    foreach ($v as $x) {
                        $safe_input[$k][] = $this->encrypt($x);
                    }
                    break;
                case 'sync_comment_content':
                    $safe_input[$k] = array();
                    foreach ($v as $x) {
                        $safe_input[$k][] = sanitize_text_field($x);
                    }
                    break;
                case 'exclude_categories':
                case 'import_to_categories':
                case 'post_types':
                    $safe_v = array();
                    foreach ($v as $x) {
                        $safe_v[] = sanitize_text_field($x);
                    }
                    $safe_input[$k] = $safe_v;
                    break;
                case 'additional_markup':
                    $safe_input[$k] = trim($v);
                    break;
                case 'use_excerpt':
                case 'use_geo':
                case 'exclude_tags':
                case 'auto_facebook':
                case 'auto_tumblr':
                case 'auto_twitter':
                case 'auto_wordpress':
                case 'debug':
                    $safe_input[$k] = intval($v);
                    break;
                case 'additional_tags':
                    if (is_string($v)) {
                        $tags = explode(',', $v);
                        $safe_tags = array();
                        foreach ($tags as $t) {
                            $safe_tags[] = sanitize_text_field($t);
                        }
                        $safe_input[$k] = $safe_tags;
                    }
                    break;
                case 'cache_expire_in':
                    $x = (int) sanitize_text_field($v);
                    if (0 !== $x) { // don't save a 0 value
                        $safe_input[$k] = $x;
                    }
                    break;
            }
        }
        return $safe_input;
    }

    private function encrypt ($str, $key = AUTH_KEY) {
        global $wpdb;
        return base64_encode($wpdb->get_var($wpdb->prepare('SELECT AES_ENCRYPT(%s,%s)', $str, $key)));
    }
    private function decrypt ($str, $key = AUTH_KEY) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SELECT AES_DECRYPT(%s,%s)', base64_decode($str), $key));
    }

    public function registerAdminMenu () {
        add_options_page(
            __('Diasposter Settings', 'diasposter'),
            __('Diasposter', 'diasposter'),
            'manage_options',
            $this->prefix . '_settings',
            array($this, 'renderOptionsPage')
        );
    }

    public function registerAdminScripts () {
        wp_register_style('diasposter', plugins_url('diasposter.css', __FILE__));
        wp_enqueue_style('diasposter');
    }

    public function addMetaBox ($post) {
        $options = get_option($this->prefix . '_settings');
        if (empty($options['post_types'])) { $options['post_types'] = array(); }
        $options['post_types'][] = 'post';
        $options['post_types'] = apply_filters($this->prefix . '_meta_box_post_types', $options['post_types']);
        foreach ($options['post_types'] as $cpt) {
            add_meta_box(
                'diasposter-meta-box',
                __('Diasposter', 'diasposter'),
                array($this, 'renderMetaBox'),
                $cpt,
                'side'
            );
        }
    }

    private function isConnectedToService () {
        $options = get_option($this->prefix . '_settings');
        return !empty($options['user_accounts']) && !empty($options['passwords']);
    }

    private function disconnectFromService () {
        $options = get_option($this->prefix . '_settings');
        $options['user_accounts'] = array();
        $options['passwords'] = array();
        update_option($this->prefix . '_settings', $options);
        return $options;
    }

    private function getTransientDiasporaData ($diaspora_handle, $transient) {
        $x = get_transient("{$this->prefix}_$transient");
        return isset($x[$diaspora_handle]) ? $x[$diaspora_handle] : false;
    }
    private function setTransientDiasporaData ($diaspora_handle, $transient, $data) {
        $options = get_option($this->prefix . '_settings');
        $ex = (!empty($options['cache_expire_in'])) ? $options['cache_expire_in'] : 10 * MINUTE_IN_SECONDS;
        $x = get_transient("{$this->prefix}_$transient");
        if (false === $x) {
            $x = array();
        }
        $x[$diaspora_handle] = $data;
        return set_transient("{$this->prefix}_$transient", $x, $ex);
    }

    public function getAspectsTransient ($diaspora_handle) {
        return $this->getTransientDiasporaData($diaspora_handle, 'aspects');
    }
    public function setAspectsTransient ($diaspora_handle, $data) {
        return $this->setTransientDiasporaData($diaspora_handle, 'aspects', $data);
    }
    public function getServicesTransient ($diaspora_handle) {
        return $this->getTransientDiasporaData($diaspora_handle, 'services');
    }
    public function setServicesTransient ($diaspora_handle, $data) {
        return $this->setTransientDiasporaData($diaspora_handle, 'services', $data);
    }
    public function getNotificationsTransient ($diaspora_handle) {
        return $this->getTransientDiasporaData($diaspora_handle, 'notifications');
    }
    public function setNotificationsTransient ($diaspora_handle, $data) {
        return $this->setTransientDiasporaData($diaspora_handle, 'notifications', $data);
    }

    public function refreshDiasporaTransients ($diaspora_handle) {
        $this->diaspora->logIn();
        $aspects = $this->diaspora->getAspects();
        $this->setAspectsTransient($this->diaspora->getDiasporaID(), $aspects);
        $services = $this->diaspora->getServices();
        $this->setServicesTransient($this->diaspora->getDiasporaID(), $services);
        $notifications = $this->diaspora->getNotifications();
        $this->setNotificationsTransient($this->diaspora->getDiasporaID(), $notifications);
        return array('aspects' => $aspects, 'services' => $services, 'notifications' => $notifications);
    }

    public function renderMetaBox ($post) {
        if (!$this->isConnectedToService()) {
            $this->showError(__('Diasposter does not yet have a connection to Diaspora*. Are you sure you connected Diasposter to your Diaspora* account?', 'diasposter'));
            return;
        }
        $options = get_option($this->prefix . '_settings');

        // Set default crossposting options for this post.
        $x = get_post_meta($post->ID, $this->prefix . '_crosspost', true);
        $d = get_post_meta($post->ID, 'diaspora_host', true);
        $e = $this->getUseExcerpt($post->ID);
        $a = $this->getPostAspects($post->ID);
        $g = $this->getUseGeoData($post->ID);
        $geo = $this->getPostGeo($post->ID);

        $id = get_post_meta($post->ID, 'diaspora_post_id', true);
        if ('publish' === $post->post_status && $id) {
?>
<p>
    <a href="<?php print esc_attr($this->getSyndicatedAddress($post->ID));?>" class="button button-small"><?php esc_html_e('View post on Diaspora*', 'diasposter');?></a>
</p>
<?php
        } else {
            $aspects  = $this->getAspectsTransient($this->diaspora->getDiasporaID());
            $services = $this->getServicesTransient($this->diaspora->getDiasporaID());
            if (false === $aspects || false === $services || false === $notifications) {
                $d_data = $this->refreshDiasporaTransients($this->diaspora->getDiasporaID());
                $aspects = $d_data['aspects'];
                $services = $d_data['services'];
            }
?>
<fieldset>
    <legend style="display:block;"><?php esc_html_e('Send this post to Diaspora*?', 'diasposter');?></legend>
    <p class="description" style="float: right; width: 75%;"><?php esc_html_e('If this post is in a category that Diasposter excludes, this will be ignored.', 'diasposter');?></p>
    <ul>
        <li><label><input type="radio" name="<?php esc_attr_e($this->prefix);?>_crosspost" value="Y"<?php if ('N' !== $x) { print ' checked="checked"'; }?>> <?php esc_html_e('Yes', 'diasposter');?></label></li>
        <li><label><input type="radio" name="<?php esc_attr_e($this->prefix);?>_crosspost" value="N"<?php if ('N' === $x) { print ' checked="checked"'; }?>> <?php esc_html_e('No', 'diasposter');?></label></li>
    </ul>
</fieldset>
<fieldset>
    <legend><?php esc_html_e('Crossposting options', 'diasposter');?></legend>
    <details open="open">
        <summary><?php esc_html_e('Destination & content', 'diasposter');?></summary>
        <p><label>
            <?php esc_html_e('Send to my Diaspora* account called', 'diasposter');?>
            <?php print $this->diasporaAccountsSelectField(array('name' => $this->prefix . '_destination'), $d);?>
        </label></p>
        <p><label>
            <?php esc_html_e('Share with my aspect(s) named', 'diasposter');?>
            <select name="diaspora_aspect_ids[]" multiple="multiple" size="<?php esc_attr_e(count($aspects) + 4);?>">
                <optgroup label="<?php esc_attr_e('Select one&hellip;', 'diasposter');?>">
                    <option value="public"<?php if (in_array('public', $a, true)) { print ' selected="selected"'; }?>><?php esc_html_e('Public', 'diasposter');?></option>
                    <option value="all_aspects"<?php if (in_array('all_aspects', $a, true)) { print ' selected="selected"'; }?>><?php esc_html_e('All Aspects', 'diasposter');?></option>
                </optgroup>
                <optgroup label="<?php esc_attr_e('&hellip;or select many', 'diasposter');?>">
                    <?php if (!empty($aspects)) { print $this->diasporaAspectsOptionsHtml($aspects); } ?>
                </optgroup>
            </select>
        </label></p>
        <p><label>
            <input type="checkbox" name="<?php esc_attr_e($this->prefix);?>_use_excerpt" value="1"
                <?php if (1 === $e) { print 'checked="checked"'; } ?>
                title="<?php esc_html_e('Uncheck to send post content as crosspost content.', 'diasposter');?>"
                />
            <?php esc_html_e('Send excerpt instead of main content?', 'diasposter');?>
        </label></p>
        <p><label>
            <input type="checkbox" name="<?php esc_attr_e($this->prefix);?>_use_geo" value="1"
                <?php if (1 === $g) { print 'checked="checked"'; } ?>
                title="<?php esc_html_e('Uncheck to omit geodata from crosspost.', 'diasposter');?>"
                />
            <?php esc_html_e('Send post location?', 'diasposter');?>
        </label></p>
    </details>
</fieldset>
<fieldset>
    <legend><?php esc_html_e('Social media broadcasts', 'diasposter');?></legend>
    <details open="open"><!-- Leave open until browsers work out their keyboard accessibility issues with this. -->
        <summary><?php esc_html_e('Social media broadcasts', 'diasposter');?></summary>
<?php
            if (!empty($services)) {
                $services_selected = array();
                foreach ($services as $srvc) {
                    $services_selected[$srvc] = get_post_meta($post->ID, $this->prefix . '_send_' . $srvc, true);
                }
                print '<ul>' . $this->diasporaServicesListItems($services, $services_selected) . '</ul>';
            } else {
                print '<p><span class="description">';
                print sprintf(
                    esc_html__('No configured services found. To broadcast your post to social media from your Diaspora* pod, you must first %1$sconfigure your connected services%2$s.', 'diasposter'),
                    '<a href="' . $this->diaspora->getPodURL() . '/services">', '</a>'
                );
                print '</span></p>';
            }
?>
    </details>
</fieldset>
<?php
        }
    }

    /**
     * Writes the HTML for the options page, and each setting, as needed.
     */
    // TODO: Add contextual help menu to this page.
    public function renderOptionsPage () {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'diasposter'));
        }
        $options = get_option($this->prefix . '_settings');
        if (empty($options['post_types'])) { $options['post_types'] = array(); }
        $options['post_types'][] = 'post';
        if (isset($_GET['disconnect']) && wp_verify_nonce($_GET[$this->prefix . '_nonce'], 'disconnect_from_diaspora')) {
            $options = $this->disconnectFromService();
?>
<div class="updated">
    <p>
        <?php esc_html_e('Disconnected from Diaspora*.', 'diasposter');?>
        <span class="description"><?php esc_html_e('The connection to Diaspora* was disestablished. You can reconnect using the same credentials, or enter different credentials before reconnecting.', 'diasposter');?></span>
    </p>
</div>
<?php
        }
?>
<h2><?php esc_html_e('Diasposter Settings', 'diasposter');?></h2>
<p class="fieldset-toc"><?php esc_html_e('Jump to options:', 'diasposter');?></p>
<ul class="fieldset-toc">
    <li><a href="#connection-to-service"><?php esc_html_e('Connection to Diaspora*', 'diasposter');?></a></li>
    <li><a href="#crossposting-options"><?php esc_html_e('Crossposting options', 'diasposter');?></a></li>
    <li><a href="#sync-options"><?php esc_html_e('Sync options', 'diasposter');?></a></li>
    <li><a href="#plugin-extras"><?php esc_html_e('Plugin extras', 'diasposter');?></a></li>
</ul>
<form method="post" action="options.php">
<?php settings_fields($this->prefix . '_settings');?>
<fieldset id="connection-to-service"><legend><?php esc_html_e('Connection to Diaspora*', 'diasposter');?></legend>
<table class="form-table" summary="<?php esc_attr_e('Required settings to connect to Diaspora*.', 'diasposter');?>">
    <tbody>
        <?php if (isset($_GET['disconnect']) && empty($options['user_accounts'])) { ?>
        <tr colspan="2">
            <td>
                <div class="updated">
                    <p><a href="<?php print "{$_SERVER['PHP_SELF']}?page={$this->prefix}_settings"?>" class="button button-primary"><?php esc_html_e('Reconnect to Diaspora*', 'disposter');?></a></p>
                </div>
            </td>
        </tr>
        <?php } else if (empty($options['user_accounts'])) { ?>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_user_accounts[]"><?php esc_html_e('Diaspora* ID', 'diasposter');?></label>
            </th>
            <td>
                <input id="<?php esc_attr_e($this->prefix);?>_user_accounts[]" name="<?php esc_attr_e($this->prefix);?>_settings[user_accounts][]" value="<?php esc_attr_e($options['user_accounts'][0]);?>" placeholder="<?php esc_attr_e('Paste your Diaspora* ID here', 'diasposter');?>" />
                <p class="description">
                    <?php esc_html_e('Your Diaspora* ID is sometimes called your D* address, and it looks like an email address.', 'diasposter');?>
                    <?php print sprintf(
                        esc_html__('If you need a Diaspora* ID, you can %s.', 'diasposter'),
                        '<a href="http://podupti.me/" target="_blank" ' .
                        'title="' . __('Get a Diaspora* ID by registering your desired address on an open D* pod.', 'diasposter') . '">' .
                        __('create one here', 'diasposter') . '</a>'
                    );?>
                </p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_passwords[]"><?php esc_html_e('Diaspora* password', 'diasposter');?></label>
            </th>
            <td>
                <input id="<?php esc_attr_e($this->prefix);?>_passwords[]" name="<?php esc_attr_e($this->prefix);?>_settings[passwords][]" type="password" value="<?php esc_attr_e($options['passwords'][0]);?>" placeholder="<?php esc_attr_e('Type your Diaspora* account password here', 'diasposter');?>" />
                <p class="description">
                    <?php esc_html_e('Your Diaspora* password is needed to connect this blog to your Diaspora* account. To protect your personal account, you should create a separate account specifically for this blog.', 'diasposter');?>
                </p>
            </td>
        </tr>
        <?php } else if (!empty($options['user_accounts'])) { ?>
        <tr>
            <th colspan="2">
                <div class="updated">
                    <p><?php // TODO: Move these to their own settings page tab? ?>
                        <input id="<?php esc_attr_e($this->prefix);?>_user_accounts[]" name="<?php esc_attr_e($this->prefix);?>_settings[user_accounts][]" type="hidden" value="<?php esc_attr_e($options['user_accounts'][0]);?>" placeholder="<?php esc_attr_e('Paste your Diaspora* ID here', 'diasposter');?>" />
                        <input id="<?php esc_attr_e($this->prefix);?>_passwords[]" name="<?php esc_attr_e($this->prefix);?>_settings[passwords][]" type="hidden" value="<?php esc_attr_e($this->decrypt($options['passwords'][0]));?>" placeholder="<?php esc_attr_e('Type your Diaspora* account password here', 'diasposter');?>" />
                        <?php esc_html_e('Connected to Diaspora*!', 'diasposter');?>
                        <a href="<?php print wp_nonce_url(admin_url('options-general.php?page=' . $this->prefix . '_settings&disconnect'), 'disconnect_from_diaspora', $this->prefix . '_nonce');?>" class="button"><?php esc_html_e('Disconnect', 'diasposter');?></a>
                        <span class="description"><?php esc_html_e('Disconnecting will stop cross-posts from appearing on or being imported from your Diaspora* stream(s), and will reset the options below to their defaults. You can re-connect at any time.', 'diasposter');?></span>
                    </p>
                </div>
            </th>
        </tr>
        <?php } ?>
    </tbody>
</table>
</fieldset>
    <?php if (!empty($options['user_accounts'])) { ?>
<fieldset id="crossposting-options"><legend><?php esc_html_e('Crossposting options', 'diasposter');?></legend>
<table class="form-table" summary="<?php esc_attr_e('Options for customizing crossposting behavior.', 'diasposter');?>">
    <tbody>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_exclude_categories"><?php esc_html_e('Do not crosspost entries in these categories:', 'diasposter');?></label>
            </th>
            <td>
                <ul id="<?php esc_attr_e($this->prefix);?>_exclude_categories">
                <?php foreach (get_categories(array('hide_empty' => 0)) as $cat) : ?>
                    <li>
                        <label>
                            <input
                                type="checkbox"
                                <?php if (isset($options['exclude_categories']) && in_array($cat->slug, $options['exclude_categories'])) : print 'checked="checked"'; endif;?>
                                value="<?php esc_attr_e($cat->slug);?>"
                                name="<?php esc_attr_e($this->prefix);?>_settings[exclude_categories][]">
                            <?php print esc_html($cat->name);?>
                        </label>
                    </li>
                <?php endforeach;?>
                </ul>
                <p class="description"><?php esc_html_e('Will cause posts in the specificied categories never to be crossposted to Diaspora*. This is useful if, for instance, you are creating posts automatically using another plugin and wish to avoid a feedback loop of crossposting back and forth from one service to another.', 'diasposter');?></p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_use_excerpt"><?php esc_html_e('Send excerpts instead of main content?', 'diasposter');?></label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['use_excerpt'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>_use_excerpt" name="<?php esc_attr_e($this->prefix);?>_settings[use_excerpt]" />
                <label for="<?php esc_attr_e($this->prefix);?>_use_excerpt"><span class="description"><?php esc_html_e('When enabled, the excerpts (as opposed to the body) of your WordPress posts will be used as the main content of your Diaspora* posts. Useful if you prefer to crosspost summaries instead of the full text of your entires to Diaspora* by default. This can be overriden on a per-post basis, too.', 'diasposter');?></span></label>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_use_geo"><?php esc_html_e('Send post geolocation data to Diaspora*?', 'diasposter');?></label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['use_geo'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>_use_geo" name="<?php esc_attr_e($this->prefix);?>_settings[use_geo]" />
                <label for="<?php esc_attr_e($this->prefix);?>_use_geo"><span class="description">
                    <?php esc_html_e('When enabled, geolocation information associated with your post will be crossposted to Diaspora*. For this to work, you will need to install a well-behaved geocoding WordPress plugin if you have not done so already. This can be overriden on a per-post basis, too.', 'diasposter');?>
                    <a href="https://codex.wordpress.org/Geodata"><?php esc_html_e('Learn more about WordPress geolocation interoperability.', 'diasposter');?></a>
                </span></label>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_post_types"><?php esc_html_e('Crosspost the following post types:', 'diasposter');?></label>
            </th>
            <td>
                <ul id="<?php esc_attr_e($this->prefix);?>_post_types">
                <?php foreach (get_post_types(array('public' => true)) as $cpt) : ?>
                    <li>
                        <label>
                            <input
                                type="checkbox"
                                <?php if (isset($options['post_types']) && in_array($cpt, $options['post_types'])) : print 'checked="checked"'; endif;?>
                                <?php if ('post' === $cpt) { print 'disabled="disabled"'; } ?>
                                value="<?php esc_attr_e($cpt);?>"
                                name="<?php esc_attr_e($this->prefix);?>_settings[post_types][]">
                            <?php print esc_html($cpt);?>
                        </label>
                    </li>
                <?php endforeach;?>
                </ul>
                <p class="description"><?php print sprintf(esc_html__('Choose which %1$spost types%2$s you want to crosspost. Not all post types can be crossposted safely, but many can. If you are not sure about a post type, leave it disabled. Plugin authors may create post types that are crossposted regardless of the value of this setting. %3$spost%4$s post types are always enabled.', 'diasposter'), '<a href="https://codex.wordpress.org/Post_Types">', '</a>', '<code>', '</code>');?></p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_additional_markup"><?php esc_html_e('Add the following markup to each crossposted entry:', 'diasposter');?></label>
            </th>
            <td>
                <textarea
                    id="<?php esc_attr_e($this->prefix);?>_additional_markup"
                    name="<?php esc_attr_e($this->prefix);?>_settings[additional_markup]"
                    placeholder="<?php esc_attr_e('Anything you type in this box will be added to every crosspost.', 'diasposter');?>"><?php
        if (isset($options['additional_markup'])) {
            print esc_textarea($options['additional_markup']);
        } else {
            print '[%the_title%](%permalink%) ' . esc_html__('was originally published on', 'diasposter') . ' [%blog_name%](%blog_url%)';
        }
?></textarea>
                <p class="description"><?php print sprintf(
                    esc_html__('Text or %1$sMarkdown%2$s you want to add to each post. Useful for things like a link back to your original post. You can use %3$s, %4$s, %5$s, and %6$s as placeholders for the cross-posted post\'s link, its title, the link to the homepage for this site, and the name of this blog, respectively.', 'diasposter'),
                    '<a href="https://diasporafoundation.org/formatting">', '</a>',
                    '<code>%permalink%</code>', '<code>%the_title%</code>',
                    '<code>%blog_url%</code>',  '<code>%blog_name%</code>'
                );?></p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_exclude_tags"><?php esc_html_e('Do not send post tags to Diaspora*', 'diasposter');?></label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['exclude_tags'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>_exclude_tags" name="<?php esc_attr_e($this->prefix);?>_settings[exclude_tags]" />
                <label for="<?php esc_attr_e($this->prefix);?>_exclude_tags"><span class="description"><?php esc_html_e('When enabled, tags on your WordPress posts are not applied to your Diaspora* posts. Useful if you maintain different taxonomies on your different sites.', 'diasposter');?></span></label>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_additional_tags">
                    <?php esc_html_e('Automatically add these tags to all crossposts:', 'diasposter');?>
                </label>
            </th>
            <td>
                <input id="<?php esc_attr_e($this->prefix);?>_additional_tags" value="<?php if (isset($options['additional_tags'])) : esc_attr_e(implode(', ', $options['additional_tags'])); endif;?>" name="<?php esc_attr_e($this->prefix);?>_settings[additional_tags]" placeholder="<?php esc_attr_e('crosspost, magic', 'diasposter');?>" />
                <p class="description"><?php print sprintf(esc_html__('Comma-separated list of additional tags that will be added to every post sent to Diaspora*. Useful if only some posts on your Diaspora* stream are cross-posted and you want to know which of your Diaspora* posts were generated by this plugin. (These tags will always be applied regardless of the value of the "%s" option.)', 'diasposter'), esc_html__('Do not send post tags to Diaspora*', 'diasposter'));?></p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_auto_twitter">
                    <?php esc_html_e('Automatically tweet a link to your Diaspora post?', 'diasposter');?>
                </label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['auto_twitter'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>_auto_twitter" name="<?php esc_attr_e($this->prefix);?>_settings[auto_twitter]" />
                <label for="<?php esc_attr_e($this->prefix);?>_auto_twitter"><span class="description"><?php print sprintf(esc_html__('When checked, new posts you create on WordPress will have their "%s" option enabled by default. You can always override this when editing an individual post.', 'diasposter'), esc_html__('Send tweet?', 'diasposter'));?></span></label>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_auto_tumblr">
                    <?php esc_html_e('Automatically make a Tumblr post about your Diaspora post?', 'diasposter');?>
                </label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['auto_tumblr'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>_auto_tumblr" name="<?php esc_attr_e($this->prefix);?>_settings[auto_tumblr]" />
                <label for="<?php esc_attr_e($this->prefix);?>_auto_tumblr"><span class="description"><?php print sprintf(esc_html__('When checked, new posts you create on WordPress will have their "%s" option enabled by default. You can always override this when editing an individual post.', 'diasposter'), esc_html__('Send Tumblr post?', 'diasposter'));?></span></label>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_auto_wordpress">
                    <?php esc_html_e('Automatically make a WordPress.com post about your Diaspora post?', 'diasposter');?>
                </label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['auto_wordpress'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>_auto_wordpress" name="<?php esc_attr_e($this->prefix);?>_settings[auto_wordpress]" />
                <label for="<?php esc_attr_e($this->prefix);?>_auto_wordpress"><span class="description"><?php print sprintf(esc_html__('When checked, new posts you create on WordPress will have their "%s" option enabled by default. You can always override this when editing an individual post.', 'diasposter'), esc_html__('Send WordPress post?', 'diasposter'));?></span></label>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_auto_facebook">
                    <?php esc_html_e('Automatically tweet a link to your Diaspora post?', 'diasposter');?>
                </label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['auto_facebook'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>_auto_facebook" name="<?php esc_attr_e($this->prefix);?>_settings[auto_facebook]" />
                <label for="<?php esc_attr_e($this->prefix);?>_auto_facebook"><span class="description"><?php print sprintf(esc_html__('When checked, new posts you create on WordPress will have their "%s" option enabled by default. You can always override this when editing an individual post.', 'diasposter'), esc_html__('Send Facebook post?', 'diasposter'));?></span></label>
            </td>
        </tr>
    </tbody>
</table>
</fieldset>
<fieldset id="sync-options"><legend><?php esc_html_e('Sync options', 'diasposter');?></legend>
<table class="form-table" summary="<?php esc_attr_e('Customize the import behavior.', 'diasposter');?>">
    <tbody>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_sync_comment_content"><?php esc_html_e('Sync comments from Diaspora*', 'diasposter');?></label>
                <p class="description"><?php esc_html_e('(This feature is experimental. Please backup your website before you turn this on.)', 'diasposter');?></p>
            </th>
            <td>
                <ul id="<?php esc_attr_e($this->prefix);?>_sync_comment_content">
                    <?php print $this->diasporaAccountsListCheckboxes(array('id' => $this->prefix . '_sync_comment_content', 'name' => $this->prefix . '_settings[sync_comment_content][]'), $options['sync_comment_content']);?>
                </ul>
                <p class="description"><?php esc_html_e("Other people's comments on your cross-posted entries on the Diaspora* accounts you select will automatically be copied back to this blog.", 'diasposter');?></p>
            </td>
        </tr>
<!--
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_import_to_categories"><?php esc_html_e('Automatically assign synced posts to these categories:');?></label>
            </th>
            <td>
                <ul id="<?php esc_attr_e($this->prefix);?>_import_to_categories">
                <?php foreach (get_categories(array('hide_empty' => 0)) as $cat) : ?>
                    <li>
                        <label>
                            <input
                                type="checkbox"
                                <?php if (isset($options['import_to_categories']) && in_array($cat->slug, $options['import_to_categories'])) : print 'checked="checked"'; endif;?>
                                value="<?php esc_attr_e($cat->slug);?>"
                                name="<?php esc_attr_e($this->prefix);?>_settings[import_to_categories][]">
                            <?php print esc_html($cat->name);?>
                        </label>
                    </li>
                <?php endforeach;?>
                </ul>
                <p class="description"><?php print sprintf(esc_html__('Will cause any posts imported from your Diaspora* account to be assigned the categories that you enable here. It is often a good idea to %screate a new category%s that you use exclusively for this purpose.', 'diasposter'), '<a href="' . admin_url('edit-tags.php?taxonomy=category') . '">', '</a>');?></p>
            </td>
        </tr>
-->
    </tbody>
</table>
</fieldset>
<fieldset id="plugin-extras"><legend><?php esc_html_e('Plugin extras', 'diasposter');?></legend>
<table class="form-table" summary="<?php esc_attr_e('Additional options to customize plugin behavior.', 'diasposter');?>">
    <tbody>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_cache_expire_in"><?php esc_html_e('Expire cached Diaspora* pod settings in', 'diasposter');?></label>
            </th>
            <td>
                <input id="<?php esc_attr_e($this->prefix);?>_cache_expire_in" name="<?php esc_attr_e($this->prefix);?>_settings[cache_expire_in]" value="<?php if (isset($options['cache_expire_in'])) { esc_attr_e($options['cache_expire_in']); }?>" type="number" min="1" placeholder="<?php esc_attr_e('number of seconds', 'diasposter');?>" />
                <p class="description">
                    <?php print sprintf(esc_html__('To improve performance, Diasposter keeps a cache of your Diaspora* settings. When you update your Diaspora* account settings on your pod, Diasposter will eventually notice the change. This option lets you tell Diasposter how long, in seconds, to wait before asking your pod if there has been a change. The default is %1$s600%2$s (ten minutes).', 'diasposter'), '<kbd>', '</kbd>');?>
                </p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>_debug">
                    <?php esc_html_e('Enable detailed debugging information?', 'diasposter');?>
                </label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['debug'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>_debug" name="<?php esc_attr_e($this->prefix);?>_settings[debug]" />
                <label for="<?php esc_attr_e($this->prefix);?>_debug"><span class="description"><?php
        print sprintf(
            esc_html__('Turn this on only if you are experiencing problems using this plugin, or if you were told to do so by someone helping you fix a problem (or if you really know what you are doing). When enabled, extremely detailed technical information is displayed as a WordPress admin notice when you take actions. If you have also enabled WordPress\'s built-in debugging (%1$s) and debug log (%2$s) feature, additional information will be sent to a log file (%3$s). This file may contain sensitive information, so turn this off and erase the debug log file when you have resolved the issue.', 'diasposter'),
            '<a href="https://codex.wordpress.org/Debugging_in_WordPress#WP_DEBUG"><code>WP_DEBUG</code></a>',
            '<a href="https://codex.wordpress.org/Debugging_in_WordPress#WP_DEBUG_LOG"><code>WP_DEBUG_LOG</code></a>',
            '<code>' . content_url() . '/debug.log' . '</code>'
        );
                ?></span></label>
            </td>
        </tr>
    </tbody>
</table>
</fieldset>
    <?php } ?>
<?php submit_button();?>
</form>
<?php
        $this->showDonationAppeal();
    } // end public function renderOptionsPage

    private function diasporaAspectsOptionsHtml ($aspects) {
        $html = '';
        foreach ($aspects as $a) {
            $html .= '<option value="' . esc_attr($a->id) . '"';
            if ($a->selected) {
                $html .= ' selected="selected"';
            }
            $html .= '>' . esc_html($a->name) . '</option>';
        }
        return $html;
    }

    private function diasporaServicesListItems ($services, $selected = array()) {
        $options = get_option($this->prefix . '_settings');
        $html = '';
        $title_str = $label_str = array();
        foreach ($services as $x) {
            $html .= '<li><label>';
            switch ($x) {
                case 'twitter':
                    $title_str[$x] = esc_html__('Uncheck to disable the auto-tweet.', 'diasposter');
                    $label_str[$x] = esc_html__('Send tweet?', 'diasposter');
                    break;
                case 'tumblr':
                    $title_str[$x] = esc_html__('Uncheck to disable the Tumblr auto-post.', 'diasposter');
                    $label_str[$x] = esc_html__('Send Tumblr post?', 'diasposter');
                    break;
                case 'wordpress':
                    $title_str[$x] = esc_html__('Uncheck to disable the WordPress auto-post.', 'diasposter');
                    $label_str[$x] = esc_html__('Send WordPress post?', 'diasposter');
                    break;
                case 'facebook':
                    $title_str[$x] = esc_html__('Uncheck to disable the Facebook auto-post.', 'diasposter');
                    $label_str[$x] = esc_html__('Send Facebook post?', 'diasposter');
                    break;
            }
            $html .= '<input type="checkbox" name="' . esc_attr($this->prefix) . '_send_' . $x . '" value="1"';
            if (isset($selected[$x]) && '' === $selected[$x]) {
                // unknown, test against global option
                if (isset($options['auto_' . $x])) {
                    $html .= checked($options['auto_' . $x], true, false);
                }
            } else if (isset($selected[$x]) && 1 === (int) $selected[$x]) {
                $html .= ' checked="checked"';
            }
            $html .= ' title="' . $title_str[$x] . '"';
            $html .= '/>';
            $html .= $label_str[$x];
            $html .= '</label></li>';
        }
        return $html;
    }

    private function diasporaAccountsSelectField ($attributes = array(), $selected = false) {
        $html = '<select';
        if (!empty($attributes)) {
            foreach ($attributes as $k => $v) {
                $html .=  ' ' . $k . '="' . esc_attr($v) . '"';
            }
        }
        $html .= '>';
        $options = get_option($this->prefix . '_settings');
        foreach ($options['user_accounts'] as $acct) {
            $html .= '<option value="' . esc_attr($acct) . '"';
            if ($selected && $selected === $acct) {
                $html .= ' selected="selected"';
            }
            $html .= '>';
            $html .= esc_html($acct);
            $html .= '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function diasporaAccountsListCheckboxes ($attributes = array(), $selected = false) {
        $html = '';
        $options = get_option($this->prefix . '_settings');
        foreach ($options['user_accounts'] as $x) {
            $html .= '<li>';
            $html .= '<label>';
            $html .= '<input type="checkbox"';
            if (!empty($attributes)) {
                foreach ($attributes as $k => $v) {
                    $html .= ' ';
                    switch ($k) {
                        case 'id':
                            $html .= $k . '="' . esc_attr($v) . '-' . esc_attr($x) . '"';
                            break;
                        default:
                            $html .= $k . '="' . esc_attr($v) . '"';
                            break;
                    }
                }
            }
            if ($selected && in_array($x, $selected)) {
                $html .= ' checked="checked"';
            }
            $html .= ' value="' . esc_attr($x) . '"';
            $html .= '>';
            $html .= esc_html($x) . '</label>';
            $html .= '</li>';
        }
        return $html;
    }

    // Modified from https://stackoverflow.com/a/4997018/2736587 which claims
    // http://www.php.net/manual/en/function.strip-tags.php#96483
    // as its source. Werksferme.
    private function strip_only($str, $tags, $stripContent = false, $limit = -1) {
        $content = '';
        if(!is_array($tags)) {
            $tags = (strpos($str, '>') !== false ? explode('>', str_replace('<', '', $tags)) : array($tags));
            if(end($tags) == '') array_pop($tags);
        }
        foreach($tags as $tag) {
            if ($stripContent) {
                $content = '(.+</'.$tag.'[^>]*>|)';
            }
            $str = preg_replace('#</?'.$tag.'[^>]*>'.$content.'#is', '', $str, $limit);
        }
        return $str;
    }

}

$diasposter = new Diasposter();
