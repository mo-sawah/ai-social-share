<?php
namespace AISS;

if (!defined('ABSPATH')) { exit; }

final class Cron {
    const HOOK = 'aiss_cron_tick';
    private $openrouter, $facebook, $x;

    public function __construct(OpenRouter $o, Facebook $f, X $x) {
        $this->openrouter=$o; $this->facebook=$f; $this->x=$x;
        add_filter('cron_schedules', [$this, 'sched']);
        add_action(self::HOOK, [$this, 'run']);
        add_action('update_option_aiss_settings', function() { self::ensure(true); });
    }

    public static function ensure($force=false) {
        if ($force || !wp_next_scheduled(self::HOOK)) {
            wp_clear_scheduled_hook(self::HOOK);
            $min = max(5, (int)Utils::get_settings()['schedule_minutes']);
            wp_schedule_event(time()+60, 'aiss_min_'.$min, self::HOOK);
        }
    }
    public static function clear_schedule() { wp_clear_scheduled_hook(self::HOOK); }
    
    public function sched($s) {
        $min = max(5, (int)Utils::get_settings()['schedule_minutes']);
        $s['aiss_min_'.$min] = ['interval'=>$min*60, 'display'=>"AI Share ($min min)"];
        return $s;
    }

    public function run() {
        update_option('aiss_last_run_time', time()); // Log run time
        $s = Utils::get_settings();
        
        // Find posts
        $args = ['post_type'=>'post', 'post_status'=>'publish', 'posts_per_page'=>(int)$s['max_posts_per_run'], 'fields'=>'ids', 'date_query'=>[['after'=>'3 days ago']]];
        if($s['filter_mode']!=='all' && $s['filter_terms']) {
            $args['tax_query']=[['taxonomy'=>$s['filter_mode']=='category'?'category':'post_tag', 'field'=>'slug', 'terms'=>explode(',',$s['filter_terms'])]];
        }

        foreach(get_posts($args) as $pid) {
            $this->process_post($pid, $s);
        }
    }

    private function process_post($pid, $s) {
        // Facebook
        if ($this->facebook->is_connected() && !get_post_meta($pid, '_aiss_fb_shared_at', true)) {
            $gen = $this->openrouter->generate_post($pid, $s['prompt_facebook']);
            if ($gen['ok']) {
                $res = $this->facebook->share_link_post($pid, $gen['text']);
                if ($res['ok']) {
                    update_post_meta($pid, '_aiss_fb_shared_at', time());
                    update_post_meta($pid, '_aiss_fb_remote_id', $res['id']);
                }
            }
        }

        // DELAY between networks (15 seconds) to appear natural
        if ($this->facebook->is_connected() && $this->x->is_connected()) {
            sleep(15);
        }

        // X (Twitter)
        if ($this->x->is_connected() && !get_post_meta($pid, '_aiss_x_shared_at', true)) {
            $gen = $this->openrouter->generate_post($pid, $s['prompt_x']);
            if ($gen['ok']) {
                $res = $this->x->post_tweet($pid, $gen['text']);
                if ($res['ok']) {
                    update_post_meta($pid, '_aiss_x_shared_at', time());
                    update_post_meta($pid, '_aiss_x_remote_id', $res['id']);
                }
            }
        }
    }
}