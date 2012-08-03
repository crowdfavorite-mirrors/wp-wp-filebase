<?php
// describes an editable list template that can be saved
// WPFB_ListTpl::Generate generates output for front-end file lists
class WPFB_ListTpl {
	
	var $tag;
	var $header;
	var $footer;
	var $file_tpl_tag;
	var $cat_tpl_tag;
		
	static function Get($tag) {
		$tag = trim($tag, '\'');
		$tpls = get_option(WPFB_OPT_NAME.'_list_tpls');
		return isset($tpls[$tag]) ? new WPFB_ListTpl($tag, $tpls[$tag]) : null;
	}
	
	static function GetAll() {
		$tpls = get_option(WPFB_OPT_NAME.'_list_tpls');
		foreach($tpls as $tag => $tpl)
			$tpls[$tag] = new WPFB_ListTpl($tag, $tpl);
		return $tpls;
	}
	
	function WPFB_ListTpl($tag=null, $data=null) {
		if(!empty($data)) {
			$vars = array_keys(get_class_vars(get_class($this)));
			foreach($vars as $var)
				if(isset($data[$var]))
					$this->$var = $data[$var];
		}				
		$this->tag = $tag;
	}
	
	function Save() {
		$tpls = get_option(WPFB_OPT_NAME.'_list_tpls');
		if(!is_array($tpls)) $tpls = array();
		$data = (array)$this;
		unset($data['tag']);
		$tpls[$this->tag] = $data; 
		update_option(WPFB_OPT_NAME.'_list_tpls', $tpls);
	}
	
	static function ParseHeaderFooter($str) {
		global $wp_query;	
		$str = preg_replace('/%sortlink:([a-z0-9_]+)%/ie', __CLASS__.'::GenSortlink(\'$1\')', $str);
		if(strpos($str, '%search_form%') !== false) {
			$searching = !empty($_GET['wpfb_s']);
			if($searching) {
				$sb = empty($wp_query->query_vars['s'])?null:$wp_query->query_vars['s']; 
				$wp_query->query_vars['s'] = $_GET['wpfb_s'];
			}
			if($searching) $wp_query->query_vars['s'] = $sb;
			
			ob_start();
			get_search_form();
			$form = ob_get_clean();
			if(empty($form)) echo "Searchform empty!";

			$form = preg_replace('/action=["\'].+?["\']/', 'action=""', $form);
			$form = str_replace('="s"', '="wpfb_s"', $form);
			$form = str_replace("='s'", "='wpfb_s'", $form);
			$gets = '';
			foreach($_GET as $name => $value) if($name != 'wpfb_s') $gets.='<input type="hidden" name="'.esc_attr(stripslashes($name)).'" value="'.esc_attr(stripslashes($value)).'" />';
			$form = str_ireplace('</form>', "$gets</form>", $form);
			$str = str_replace('%search_form%', $form, $str);
		}
		return $str;
	}
	
	static function GenSortlink($by) {
		static $link;
		if(empty($link)) {
			$link = remove_query_arg('wpfb_file_sort');
			$link .= ((strpos($link, '?') > 0)?'&':'?').'wpfb_file_sort=&';	
		}
		$desc = !empty($_GET['wpfb_file_sort']) && ($_GET['wpfb_file_sort'] == $by || $_GET['wpfb_file_sort'] == "<$by"); 
		return $link.($desc?'gt;':'lt;').$by;
	}
	
	function Generate($categories, $show_cats, $file_order, $page_limit)
	{
		$content = self::ParseHeaderFooter($this->header);
		$hia = WPFB_Core::GetOpt('hide_inaccessible');
		$sort = WPFB_Core::GetFileListSortSql($file_order);
		
		if($show_cats) $cat_tpl = WPFB_Core::GetParsedTpl('cat', $this->cat_tpl_tag);
		$file_tpl = WPFB_Core::GetParsedTpl('file', $this->file_tpl_tag);
		
		if($page_limit > 0) { // pagination
			$page = (empty($_REQUEST['wpfb_list_page']) || $_REQUEST['wpfb_list_page'] < 1) ? 1 : intval($_REQUEST['wpfb_list_page']);
			$start = $page_limit * ($page-1);
		} else $start = -1;

		if(!empty($_GET['wpfb_s'])) { // search
			wpfb_loadclass('Search');
			$where = WPFB_Search::SearchWhereSql(WPFB_Core::GetOpt('search_id3'), $_GET['wpfb_s']);
		} else $where = '1=1';
		
		$num_total_files = 0;
		if(is_null($categories)) { // if null, just list all files!
			$files = WPFB_File::GetFiles2($where, $hia, $sort, $page_limit, $start);
			$num_total_files = WPFB_File::GetNumFiles2($where, $hia);
			foreach($files as $file)
				$content .= $file->GenTpl($file_tpl);
		} else {
			$cat = reset($categories); // get first category
			if(count($categories) == 1 && $cat->cat_num_files > 0) { // single cat
				if(!$cat->CurUserCanAccess()) return '';
				if($show_cats) $content .= $cat->GenTpl($cat_tpl);
				$where = "($where) AND file_category = $cat->cat_id";
				$files = WPFB_File::GetFiles2($where, $hia, $sort, $page_limit, $start);
				$num_total_files = WPFB_File::GetNumFiles2($where, $hia);
				foreach($files as $file)
					$content .= $file->GenTpl($file_tpl);	
			} else { // multi-cat
				// TODO: multi-cat list pagination does not work properly yet
				
				// special handling of categories that do not have files directly: list child cats!
				if(count($categories) == 1 && $cat->cat_num_files == 0) {
					$categories = $cat->GetChildCats(true, true);
				}		
				
				if($show_cats) { // group by categories
					$n = 0;
					foreach($categories as $cat)
					{
						if(!$cat->CurUserCanAccess()) continue;
						
						$num_total_files = max($nf = WPFB_File::GetNumFiles2("($where) AND file_category = $cat->cat_id", $hia), $num_total_files); // TODO
						
						//if($n > $page_limit) break; // TODO!!
						if($nf > 0) {
							$files = WPFB_File::GetFiles2("($where) AND file_category = $cat->cat_id", $hia, $sort, $page_limit, $start);
							if($show_cats && count($files) > 0)
								$content .= $cat->GenTpl($cat_tpl); // check for file count again, due to pagination!
								
							foreach($files as $file) {
								$content .= $file->GenTpl($file_tpl);
								$n++;
							}
						}
					}
				} else {
					// this is not very efficient, because all files are
					$all_files = array();
					foreach($categories as $cat)
					{
						if(!$cat->CurUserCanAccess()) continue;						
						$all_files += WPFB_File::GetFiles2("($where) AND file_category = $cat->cat_id", $hia, $sort);
					}
					$num_total_files = count($all_files);
					
					WPFB_Item::Sort($all_files, $sort);
					
					$keys = array_keys($all_files);
					if($start == -1) $start = 0;
					$last = min($start + $page_limit, $num_total_files);
					for($i = $start; $i < $last; $i++)
						$content .= $all_files[$keys[$i]]->GenTpl($file_tpl);
				}
			}
		}
		
		$footer = self::ParseHeaderFooter($this->footer);
		
		if($page_limit > 0 && $num_total_files > $page_limit) {
			$pagenav = paginate_links( array(
				'base' => add_query_arg( 'wpfb_list_page', '%#%' ),
				'format' => '',
				'total' => ceil($num_total_files / $page_limit),
				'current' => empty($_GET['wpfb_list_page']) ? 1 : absint($_GET['wpfb_list_page'])
			));
			/*
			'show_all' => false,
			'prev_next' => true,
			'prev_text' => __('&laquo; Previous'),
			'next_text' => __('Next &raquo;'),
			'end_size' => 1,
			'mid_size' => 2,
			'type' => 'plain',
			'add_args' => false, // array of query args to add
			'add_fragment' => ''*/		

			if(strpos($footer, '%page_nav%') === false)
				$footer .= $pagenav;
			else
				$footer = str_replace('%page_nav%', $pagenav, $footer);
		} else {
			$footer = str_replace('%page_nav%', '', $footer);
		}
		
		$content .= $footer;

		return $content;
	}
	
	function Sample($cat, $file) {
		$cat_tpl = WPFB_Core::GetParsedTpl('cat', $this->cat_tpl_tag);
		$file_tpl = WPFB_Core::GetParsedTpl('file', $this->file_tpl_tag);
		$footer = str_replace('%page_nav%', paginate_links(array(
			'base' => add_query_arg( 'wpfb_list_page', '%#%' ), 'format' => '',
			'total' => 3,
			'current' => 1
		)), self::ParseHeaderFooter($this->footer));
		return self::ParseHeaderFooter($this->header) . $cat->GenTpl($cat_tpl) . $file->GenTpl($file_tpl) . $footer;		
	}
	
	function Delete() {
		$tpls = get_option(WPFB_OPT_NAME.'_list_tpls');
		if(!is_array($tpls)) return;
		unset($tpls[$this->tag]);
		update_option(WPFB_OPT_NAME.'_list_tpls', $tpls);
	}
	
	function GetTitle() { return __(__(esc_html(WPFB_Output::Filename2Title($this->tag))), WPFB); }
}