<?php

namespace dokuwiki\plugin\gitbacked;

/**
 * Class CommitInfo
 * 
 * Collection of handling git commit info used in gitbackend
 * 
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Sungbin Jeon <clockoon@gmail.com>
 */

 class CommitInfo {
    // @var array
    protected $info = array();

    /**
     * Constructor: assign info
     */
    public function __construct($info = null) {
        $this->info = $info;
    }

    /**
     * Return or set a value of associated key of revision information
     * but does not allow to change values of existing keys
     *
     * @param string $key
     * @param mixed $value
     * @return string|null
     */
    public function val($key, $value = null) {
        //print_r($this->info);
        if (isset($value) && !array_key_exists($key, $this->info)) {
            // setter, only for new keys
            $this->info[$key] = $value;
        }
        if (array_key_exists($key, $this->info)) {
            // getter
            return $this->info[$key];
        }
        return null;
    }

    public function showCommitIcon() {
        $id = $this->val('hash');
        return '<img class="icon" src="'.DOKU_BASE.'lib/images/fileicons/file.png" alt="'.$id.'" />';
    }

    public function showCommitDate() {
        $formatted = $this->val('date');
        return '<span class="date">' . $formatted . '</span>';
    }

    public function showCommitComment() {
        return '<span class="comment">' . $this->val('comment') . '</span>';
    }

    public function showCommitAuthor() {
        return '<span class="author">' . $this->val('author') . '</span>';
    }
    
    public function showCommitFiles() {
        $files = $this->val('files');
        $html = '';
        foreach ($files as $file) {
            $status = $file['status'];
            $filename = $file['filename'];
            
            // skip .gitignore;
            if (strpos('.gitignore',$filename) !== false) continue;

            $html = $html . '<li class="gitrecent">';
            
            $pageid = $this->filenameToId($filename);
            // ACL check
            if (auth_quickaclcheck($pageid) < AUTH_READ) continue;
            $mode = $this->getMode($filename);

            // add html
            $html = $html . implode(" \n", [
                $this->showIconCompareWithPrevious($filename), // diff link icon
                $this->showIconRevisions($filename), // revision link
                $this->showFileStatus($status), // git log file status
                $this->showFileName($filename), // file name
            ]);
            $html = $html . '</li>';
        }

        return $html;
    }

    private function showIconCompareWithPrevious(string $filename) {
        return '';
    }

    private function showIconRevisions(string $filename) {
        global $lang;

        $id = $this->filenameToId($filename);

        $href = wl($id, ['do'=>'gitrevisions'], false, '&');
        return '<a href="'.$href.'" class="revisions_link">'
              . '<img src="'.DOKU_BASE.'lib/images/history.png" width="12" height="14"'
              . ' title="'.$lang['btn_revs'].'" alt="'.$lang['btn_revs'].'" />'
              . '</a>';

    }

    private function showFileName(string $filename) {
        $id = $this->filenameToId($filename);

        switch ($this->getMode($filename)) {
            case 'media': // media file revision
                $params = ['tab_details'=> 'view', 'ns'=> getNS($id), 'image'=> $id];
                $href = media_managerURL($params, '&');
                $display_name = $id;
                $exists = file_exists(mediaFN($id, '')); // current only;
                break;
            case 'page': // page revision
                $href = wl($id, '', false, '&'); // current only
                $display_name = useHeading('navigation') ? hsc(p_get_first_heading($id)) : $id;
                if (!$display_name) $display_name = $id;
                $exists = page_exists($id, ''); //current only
        }

        if($exists) {
            $class = 'wikilink1';
        } else {
            $class = 'wikilink2';
            // if($this->isCurrent()) {
            //     //show only not-existing link for current page, which allows for directly create a new page/upload
            //     $class = 'wikilink2';
            // } else {
            //     //revision is not in attic
            //     return $display_name;
            // }
        }
        if ($this->val('type') == DOKU_CHANGE_TYPE_DELETE) {
            $class = 'wikilink2';
        }
        return '<a href="'.$href.'" class="'.$class.'">'.$display_name.'</a>';
    }

    private function showFileStatus(string $status) {
        return '<span class="status">' . $status . '</span>';
    }

    // convert filename to DW-compatible id
    private function filenameToId(string $filename) {
        global $conf;

        if ($conf['useslash'] == 0) {
            return preg_replace('/(.*)\..*/','$1',str_replace("/", ":", $filename));
        } else {
            return preg_replace('/(.*)\..*/','$1',$filename);
        }
    }

    private function getMode(string $filename) {
        global $conf;
        $conf['pageextension'] = 'md';

        $extension = preg_replace('/.*\.(.*)/',"$1",$filename);
        if ($extension == $conf['pageextension']) {
            return 'page';
        } else {
            return 'media';
        }
    }
 }