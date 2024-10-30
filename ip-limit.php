<?php
/*
 Plugin Name: IP LIMIT
 Description: IP制限プラグイン - View filtering by IP
 Author: pie001
 Version: 1.0
*/

$ip_limit = new IP_Limit();

class IP_Limit {

	private static $options = NULL;
	const NAME = 'ip-limit';
	const DOMAIN = 'ip-limit';
	const OPTIONS = 'ip_limit_option';
	const IP_LIST = 'ip_list';

	/**
	 * __construct
	 * 初期化等
	 */
	public function __construct() {

		// 有効化した時の処理
		register_activation_hook( __FILE__, array( __CLASS__, 'activation' ) );
		// アンインストールした時の処理
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );


		// 記事保存時にIP制限情報を保存
		add_action( 'save_post', array( $this, 'save_post' ), '', 3);

		// 記事のステータス変更字にIP制限情報を保存
		// (公開お知らせメールに公開種別を表示するため、お知らせメールhookより先に値を保存しておく)
		add_filter('transition_post_status', array( $this, 'transition_post_status' ) , 1, 3);

		// メタボックスの追加
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// 管理画面の投稿一覧に「公開制限」項目を追加する
		add_filter('manage_posts_columns',  array( $this, 'add_ip_limit_columns' ), 9);
		add_action('manage_posts_custom_column', array( $this, 'add_ip_limit_column_detail' ), 9, 2);
		add_filter('manage_pages_columns', array( $this, 'add_ip_limit_columns' ), 9);
		add_action('manage_pages_custom_column', array( $this, 'add_ip_limit_column_detail' ), 9, 2);

		// feedに表示される記事は外部公開のみ。コメントは全て非表示。
		if(strpos($_SERVER['REQUEST_URI'], "feed")){
			add_filter( 'posts_where_request', array( $this,'filter_posts_where_request'), 10);
			add_filter( 'posts_where', array( $this,'filter_posts_where_request'), 10);
 			add_filter( 'posts_where_paged', array( $this,'filter_posts_where_request'), 10);
 			// 表示制御ユーザーの場合、コメント数は全て0と表示する
 			add_filter('get_comments_number', array( $this,'filter_get_comments_number'));
		}

		// rss コメント取得条件
		add_filter( 'comment_feed_where',array( $this,'filter_comment_feed_where'), 10);

		// 管理画面以外の場合、且つクライアントのIPが許容外IPの場合、表示制御を行う
		if(!is_admin() && $this->is_ip_limit()){
			// 記事の取得条件追加(画面/rss)
			// クエリーで取得済みのデータを絞る処理
			add_filter( 'posts_where_request', array( $this,'filter_posts_where_request'), 10);
			add_filter( 'posts_where', array( $this,'filter_posts_where_request'), 10);
			add_filter( 'posts_where_paged', array( $this,'filter_posts_where_request'), 10);

			// 前ページの取得
			add_filter( 'get_previous_post_where', array( $this,'filter_get_previous_next_post_where'), 10);

			// 後ページの取得
			add_filter( 'get_next_post_where', array( $this,'filter_get_previous_next_post_where'), 10);

			// コメント一覧取得条件(外部公開のコメントのみ表示したい場合)
			add_filter( 'comments_clauses',array( $this,'filter_where_comments_clauses'), 10);

			// コメント結果フィルター(全てのコメントを非表示にする場合)
			add_filter( 'get_comments',  array( $this,'filter_get_comments'), 10, 1 );

			// 表示制御ユーザーの場合、コメント数は全て0と表示する
			add_filter('get_comments_number', array( $this,'filter_get_comments_number'));

			// 表示制御ユーザーの場合、コメント欄を非表示にする
			add_filter( 'comments_open', array(  $this,'filter_get_comments_number'));
		}
	}

	/**
	 * コメント結果フィルター
	 * */
	function filter_get_comments( $results )
	{
		$results = null;
		return $results;
	}

	/**
	 * 表示制御ユーザーの場合、コメント欄を非表示にする
	 * */
	function filter_comments_open($open){
		$open = 'closed';
		return $open;
	}

	/**
	 * 表示制御ユーザーの場合、コメント数は全て0と表示する
	 * */
	function filter_get_comments_number($count){
		$count = 0; // example you can add one to the count, etc
		return $count; //return it when finished!
	}

	/**
	 * 既存クエリーに制限条件を追加する
	 * */
	public function filter_where_comments_clauses( $query ) {
		// 外部公開の記事のコメントも非表示にする
		$query['where'] .= " AND ( comment_post_ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = '".self::NAME."' and  meta_value='x' ) )";
		return $query;
	}

	public function filter_posts_where_request($query) {
		$query .= " AND ( wp_posts.ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = '".self::NAME."' and meta_value='external' ) ) ";
		return $query;
	}

	public function filter_comment_feed_where($query) {
		// 外部公開の記事のコメントも非表示にする
		$query .= " AND ( comment_post_ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = '".self::NAME."' and meta_value='y' ) ) ";
		return $query;
	}

	public function filter_get_previous_next_post_where($query) {
		$query .= " AND ( p.ID IN ( SELECT post_id FROM wp_postmeta WHERE  meta_key = '".self::NAME."' and meta_value='external' ) ) ";
		return $query;
	}

	/**
	 * _check_ip_access_allow
	 * アクセス制限がかかっているか判定
	 * @param	$ips
	 * @return	Boolean
	 */
	public function _check_ip_access_allow( $ips ) {

		// ユーザーIPを取得
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			//check ip from share internet
			$remoteIp = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			//to check ip is pass from proxy
			$remoteIp = $_SERVER['HTTP_X_FORWARDED_FOR'];
			$ip_array = explode(",", $remoteIp);
			$remoteIp = $ip_array[0];
		} else {
			$remoteIp = $_SERVER['REMOTE_ADDR'];
		}

		// ローカル環境対応
		if($remoteIp == '::1'){
			$remoteIp = '127.0.0.1';
		}

		$allowIps = array_map('trim', explode( "<br />", $ips ));

		foreach ( $allowIps as $allowIp ){
			$allowIp = trim( $allowIp );
			if ( preg_match( '/\/\d+$/', $allowIp ) ) {
				list( $ip, $mask ) = explode( '/', $allowIp);
				$remoteLong = ip2long( $remoteIp ) >> ( 32 - $mask );
				$allowLong = ip2long( $ip ) >> ( 32 - $mask );
			} else {
				$remoteLong = ip2long( $remoteIp );
				$allowLong = ip2long( $allowIp );
			}

			// アクセス元IPの形式が不正
			if ( $remoteLong === false || $remoteLong === -1 )
				continue;
			// 許可IPの形式が不正
			if ( $allowLong === false || $allowLong === -1 )
				continue;
			// アクセス元IPが許可IPに含まれる
			if ( $remoteLong == $allowLong ) {
				return true;
			}
		}
		return false;
	}

	public function is_ip_limit(){

		$options = get_option(self::OPTIONS);
		return !$this->_check_ip_access_allow( $options['ip_list'] );
	}

	public function add_ip_limit_columns($columns) {
		$columns[self::NAME] = '公開制限';
		return $columns;
	}
	public function add_ip_limit_column_detail($column, $post_id) {
		if($column == self::NAME) {
			$post_ip_limit = get_post_meta($post_id, self::NAME, true);
			if($post_ip_limit == 'external'){
				$val = '外部公開';
			}else if($post_ip_limit == 'internal'){
				$val = '内部公開';
			}else{
				$val = '—';
			}

			echo $val;
		}
	}

	/**
	 * activation
	 * 有効化した時の処理
	 */
	public static function activation() {

	}

	/**
	 * uninstall
	 * アンインストールした時の処理
	 */
	public static function uninstall() {
		// IPリストを削除
		delete_option(self::OPTIONS);
	}

	/**
	 * save_post
	 * 記事保存時に公開制限の情報を保存
	 * @param	$post_ID
	 */
	public function save_post( $post_ID, $post, $update ) {
		if($post->post_type == 'revision')
			return $post_ID;
		if ( ! isset( $_POST[self::NAME.'_nonce'] ) )
			return $post_ID;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $post_ID;
		if ( !wp_verify_nonce( $_POST[self::NAME.'_nonce'], self::NAME ) )
			return $post_ID;

		// ラジオボタンの表示制限内容を取得して、postmetaテーブルに格納(キー：ip-limit)
		$view_flg = $_POST[self::NAME];
		if ( empty( $view_flg ) ) {
			delete_post_meta( $post_ID, self::NAME);
		}else{
			update_post_meta( $post_ID, self::NAME, $view_flg );
		}
	}

	/**
	 * transition_post_status
	 * ステータスが変更されるとき、情報を保存
	 * @param	$newstatus, $oldstatus, $object
	 */
	public function transition_post_status( $newstatus, $oldstatus, $post ) {
		if($post->post_type == 'revision')
			return $post->ID;
		if ( ! isset( $_POST[self::NAME.'_nonce'] ) )
			return $post->ID;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $post->ID;
		if ( !wp_verify_nonce( $_POST[self::NAME.'_nonce'], self::NAME ) )
			return $post->ID;

		// ラジオボタンの表示制限内容を取得して、postmetaテーブルに格納(キー：ip-limit)
		$view_flg = $_POST[self::NAME];
		if ( empty( $view_flg ) ) {
			delete_post_meta( $post->ID, self::NAME);
		}else{
			update_post_meta( $post->ID, self::NAME, $view_flg );
		}
	}

	/**
	 * 管理者画面の投稿画面のメニューブロックにプラグインのサブメニューを追加する
	 */
	public function admin_menu() {
		$post_types = get_post_types( array( 'public' => true ) );
		unset( $post_types['attachment'] );
		unset( $post_types['links'] );
		foreach ( $post_types as $post_type ) {
			add_meta_box( self::NAME, '公開制限', array( $this, 'add_meta_box' ), $post_type, 'side' );
		}

		// 管理画面に設定ページを追加
		add_options_page('IP公開制限', 'IP公開制限', 'manage_options', self::NAME, array(&$this, 'plugin_options_page'));
		// 管理画面の設定の初期化
		add_action( 'admin_init', array( $this, 'page_init' ) );
	}

	/**
	 * Add options page
	 */
	public function add_plugin_page()
	{
		add_options_page(
				'Settings Admin',
				'My Settings',
				'manage_options',
				'ip-limit-setting-admin',
				array( $this, 'create_admin_page' )
		);
	}

	/**
	 * 許可するIPを入力するメタボックスを出力
	 */
	public function add_meta_box() {
		global $post;
		$ip_restrictions = get_post_meta( $post->ID, self::NAME, true );
		?>
		<input type="hidden" name="<?php echo esc_attr( self::NAME ); ?>_nonce" value="<?php echo wp_create_nonce( self::NAME ); ?>" />
		<label><input type="radio" name="<?php echo esc_attr( self::NAME ); ?>" id="<?php echo esc_attr( self::NAME ); ?>-external" value="external" <?php checked( $ip_restrictions, 'external' ); ?>/> 外部公開</label>
		<label><input type="radio" name="<?php echo esc_attr( self::NAME ); ?>" id="<?php echo esc_attr( self::NAME ); ?>-internal" value="internal" <?php if ($ip_restrictions != 'external'){echo 'checked';} ?> /> 内部公開</label>
 		<p class="howto">
 		<ul>
 			<li>１、投稿の表示制限を行います。</li>
 			<li>
 				外部公開：閲覧制限なしで公開します。
 			</li>
 			<li>
				内部公開：閲覧制限ありで公開します(許容したIPからのアクセスのみ閲覧可)。
 			</li>
			<li>２、リビジョンでは表示制限を変更することは出来ません。(変更しても反映されません)</li>
 		</ul>
 		<p>
		<?php
	}

	function plugin_options_page() {

	    // Set class property
		self::$options = get_option(self::OPTIONS);
        ?>

        <div class="wrap">
            <?php screen_icon(); ?>
            <h2>IP公開制限</h2>
			<p>IPリストを元に投稿と固定ページの表示制限を行うプラグインです。<br />
			内部公開と設定されている投稿と固定ページは外部から閲覧することは出来ません。</p>
			<h3>IP Limitの表示制限について</h3>
			<p>画面の表示制限：投稿一覧、投稿画面の前ページ&後ページ、最近の投稿、コメント一覧、最近のコメント<br />
			その他、投稿のRSSとコメントのRSSよる記事とコメントの取得制限を行います。<br />
			※RSSで取得できる記事は「外部公開」の記事のみです。コメントは取得できません。
			</p>

            <form method="post" action="options.php">
            <?php
                settings_fields( self::OPTIONS.'_group' );
                do_settings_sections( 'ip-limit-setting-admin' );
                ?>
                <?php
                submit_button();
            ?>
            </form>
        </div>
        <?php
	}


	/**
	 * 管理画面の設定
	 */
	public function page_init()
	{
		register_setting(
				self::OPTIONS.'_group',
				self::OPTIONS,
				array( $this, 'sanitize' )
		);

		add_settings_section(
				'setting_section_id',
				'IP公開制限の設定',
				array( $this, 'print_section_info' ),
				'ip-limit-setting-admin'
		);

		add_settings_field(
				self::IP_LIST,
				'IPリスト',
				array( $this, 'ip_list_callback' ),
				'ip-limit-setting-admin',
				'setting_section_id'
		);
	}

	/**
	 * Sanitize each setting field as needed
	 *
	 * @param array $input Contains all settings fields as array keys
	 */
	public function sanitize( $input )
	{
		$new_input = array();
		if( isset( $input[self::IP_LIST] ) ){
			$new_input[self::IP_LIST] = str_replace("|", "<br />", sanitize_text_field( str_replace("\r\n", "|", $input[self::IP_LIST]) ));
		}

		return $new_input;
	}

	/**
	 * Print the Section text
	 */
	public function print_section_info()
	{
		print '<p>内部アクセスを許可するIP一覧を入力してください。<br />
				<span style="color:red;">IPリストが空の場合は全てのIPに対し、外部公開の投稿/固定ページしか表示されません。</span></p>';
	}

	/**
	 * Get the settings option array and print one of its values
	 */
	public function ip_list_callback()
	{
		printf(
				'<textarea id="ip_list" name="'.self::OPTIONS.'['.self::IP_LIST.']" cols="40" rows="15" required>%s</textarea>',
				isset( self::$options[self::IP_LIST] ) ? esc_attr(str_replace("<br />", "\r\n", self::$options[self::IP_LIST]))  : ''
		);

		print '<p>サブネットマスクの使用が可能です。<br />改行で許可したいIPアドレスを記述してください。</p>';
	}
}

