<?php
/**
 * DokuWiki Plugin gitbacked (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Wolfgang Gassler <wolfgang@gassler.org>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'action.php';
require_once dirname(__FILE__).'/../lib/Git.php';

use dokuwiki\Form\Form;
use dokuwiki\plugin\gitbacked\CommitInfo;


class action_plugin_gitbacked_history extends DokuWiki_Action_Plugin {
    private $recents = array(); // processed git log

    function __construct() {
        global $conf;
        $this->temp_dir = $conf['tmpdir'].'/gitbacked';
        io_mkdir_p($this->temp_dir);
    }

    public function register(Doku_Event_Handler $controller) {

        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'preprocess_init');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'render_init');
    }

    public function preprocess_init(Doku_Event $event, $args) {
		// Catch the good request
		if ($event->data == 'gitrecent') {
            $this->process_gitrecent($event, $args);
            $event->preventDefault();
		} elseif ($event->data == 'gitrevisions') {
            $this->process_gitrevisions($event, $args);
            $event->preventDefault();
        } else {
            return;
        }
	}

    public function render_init(Doku_Event $event, $args) {
		// Catch the good request
		if ($event->data == 'gitrecent') {
            $this->show_gitrecent($event, $args);
		} elseif ($event->data == 'gitrevisions') {
            $this->show_gitrevisions($event, $args);
        } else {
            return;
        }
	}

    private function process_gitrecent(Doku_Event &$event, $param) {
        $repo = $this->initRepo();

        // fetch log for entire repo
        $rawlog = $repo->log($format='%H|%ad|%s|%an');

        // parse rawlog
        $separator = "\r\n";
        $commitpattern = "/(.*)\|(.*)\|(.*)\|(.*)/";
        $filepattern = "/([A|C|D|M|R|T|U|X|B])\d*\t(.*)/";
        $commitcnt = 0;
        $commit = array();

        $line = strtok($rawlog, $separator); // extract first line

        while ($line !== false) {
            // process commit entry
            if(preg_match($commitpattern, $line, $matches)) {
                if($commitcnt > 0) {
                    $this->recents[] = $commit; // push previous commit info
                }
                $commit = array(
                    "hash" => $matches[1],
                    "date" => $matches[2],
                    "comment" => $matches[3],
                    "author" => $matches[4],
                    "files" => array()
                );
                $commitcnt = $commitcnt + 1;
            } elseif(preg_match($filepattern,$line,$matches)) {
                $commit['files'][] = array(
                    "status" => $matches[1],
                    "filename" => stripcslashes($matches[2])
                );
            }
            $line = strtok($separator);
        }
        $this->recents[] = $commit; // push final commit info

        //debug
        //print_r($this->$recents);
    }

    /**
     * Display recent changes bassed on git repo
     * follow dokuwiki\UI\Recent 
     *
     * @author Sungbin Jeon <sclockoon@gmail.com>
     * 
     * @return void
     */
    private function show_gitrecent(Doku_Event &$event, $param) {
        global $conf, $lang;
        global $ID;

        // print intro
        print p_locale_xhtml('recent');

        if (getNS($ID) != '') {
            print '<div class="level1"><p>'
                . sprintf($lang['recent_global'], getNS($ID), wl('', 'do=recent'))
                .'</p></div>';
        }

        // create the form
        $form = new Form(['id'=>'dw__gitrecent', 'method'=>'GET', 'action'=> wl($ID), 'class'=>'changes']);
        $form->addTagOpen('div')->addClass('gitrecent');
        $form->setHiddenField('sectok', null);
        $form->setHiddenField('do', 'gitrecent');
        $form->setHiddenField('id', $ID);

        // show dropdown selector, whether include not only recent pages but also recent media files?

        // start listing of recent items
        $form->addTagOpen('ul');
        foreach ($this->recents as $recent) {
            $commitInfo = new CommitInfo($recent);
            $form->addTagOpen('div')->addClass('li');
                $html = implode(" \n", [
                    $commitInfo->showCommitIcon(), // show commit icon with caption of hash
                    $commitInfo->showCommitDate(), // commit datetime
                    $commitInfo->showCommitComment(), // commit comment
                    $commitInfo->showCommitAuthor(), // commit author
                ]);
                $form->addHTML($html);
                $form->addTagOpen('ul'); //display:inline-block, vertical-align:top
                    $form->addTagOpen('li');
                    $html = implode(" \n", [
                        $commitInfo->showCommitFiles(), // show lists of commited files
                    ]);
                    $form->addHTML($html);
                    $form->addTagClose('li');
                $form->addTagClose('ul');
            $form->addTagClose('div');
            $form->addTagClose('li');
        }
        $form->addTagClose('ul');

        $form->addTagClose('div'); // close div class=no

        // provide navigation for paginated recent list (of pages and/or media files)
        //$form->addHTML($this->htmlNavigation($first, $hasNext));

        print $form->toHTML('Recent');
    }

    private function initRepo() {
        //get path to the repo root (by default DokuWiki's savedir)
        if(defined('DOKU_FARM')) {
            $repoPath = $this->getConf('repoPath');
        } else {
            $repoPath = DOKU_INC.$this->getConf('repoPath');
        }
        //set the path to the git binary
        $gitPath = trim($this->getConf('gitPath'));
        if ($gitPath !== '') {
            Git::set_bin($gitPath);
        }
        //init the repo and create a new one if it is not present
        io_mkdir_p($repoPath);
        $repo = new GitRepo($repoPath, $this, true, true);
        //set git working directory (by default DokuWiki's savedir)
        $repoWorkDir = DOKU_INC.$this->getConf('repoWorkDir');
        Git::set_bin(Git::get_bin().' --work-tree '.escapeshellarg($repoWorkDir));

        $params = str_replace(
            array('%mail%','%user%'),
            array($this->getAuthorMail(),$this->getAuthor()),
            $this->getConf('addParams'));
        if ($params) {
            Git::set_bin(Git::get_bin().' '.$params);
        }
        return $repo;
    }

    private function getAuthor() {
        return $GLOBALS['USERINFO']['name'];
    }

    private function getAuthorMail() {
        return $GLOBALS['USERINFO']['mail'];
    }

    /**
	 * Notifies success on git command
	 *
	 * @access  public
	 * @param   string  repository path
	 * @param   string  current working dir
	 * @param   string  command line
	 * @return  bool
	 */
	public function notify_command_success($repo_path, $cwd, $command) {
		if (!$this->getConf('notifyByMailOnSuccess')) {
			return false;
		}
		$template_replacements = array(
			'GIT_REPO_PATH' => $repo_path,
			'GIT_CWD' => $cwd,
			'GIT_COMMAND' => $command
		);
		return $this->notifyByMail('mail_command_success_subject', 'mail_command_success', $template_replacements);
	}
}