<?php

// File format constants matching Pascal implementation
define('NFHDR_ID', 'GFKNT');
define('NFHDR_ID_OLD', 'GFKNX');
define('NFHDR_ID_ENCRYPTED', 'GFKNE');
define('NF_COMMENT', '#');
define('NF_EOF', '#!EOF!#');
define('NF_TabNote', '#!RTF!#');
define('NF_TreeNote', '#!TRE!#');

// Add new constants for tree notes
define('TRE_NODE_BEGIN', '#!BeginNode!#');
define('TRE_NODE_END', '#!EndNode!#');
define('TRE_NODE_VIRTUAL', '#!VirtualNode!#');

// Add encryption constants
define('CRYPT_BLOCK_SIZE', 8);
define('CRYPT_KEY_SIZE', 32);

// Update encryption constants
define('CIPHER_METHOD', 'AES-256-CBC');
define('HASH_ALGO', 'sha256');

// File format types
class NoteFileFormat {
    const nffKeyNote = 0;
    const nffEncrypted = 1;
    const nffDartNotes = 2;
}

// Add note types
class NoteType {
    const ntRTF = 0;
    const ntTree = 1;
}

// Add virtual node modes
class VirtualMode {
    const vmNone = 0;
    const vmLinked = 1;
    const vmMirror = 2;
}

class KFile {
    private $version;
    private $filename;
    private $fileFormat;
    private $description;
    private $comment;
    private $dateCreated;
    private $activeNote;
    private $notes;
    private $modified;
    private $readOnly;
    private $cryptMethod;
    private $passphrase;

    public function __construct() {
        $this->version = '';
        $this->filename = '';
        $this->description = '';
        $this->comment = '';
        $this->dateCreated = time();
        $this->activeNote = -1;
        $this->notes = array();
        $this->modified = false;
        $this->readOnly = false;
        $this->cryptMethod = '';
        $this->passphrase = '';
    }

    public function load($filename) {
        if (!file_exists($filename)) {
            throw new Exception("Cannot open '$filename': File not found");
        }
        $this->filename = $filename;
        $this->fileFormat = NoteFileFormat::nffKeyNote; // default format

        $handle = fopen($filename, 'r');
        if (!$handle) {
            throw new Exception("Failed to open file: $filename");
        }

        try {
            $header = fgets($handle, 13); // Format detection
            if (strpos($header, NFHDR_ID) !== false ||
                strpos($header, NFHDR_ID_OLD) !== false) {
                $this->fileFormat = NoteFileFormat::nffKeyNote;
            } elseif (strpos($header, NFHDR_ID_ENCRYPTED) !== false) {
                $this->fileFormat = NoteFileFormat::nffEncrypted;
                return $this->loadEncrypted($filename);
            }
            // Parse the file contents using one branch per marker.
            while (!feof($handle)) {

                $line = fgets($handle);
                if (!$line) continue;
                $line = trim($line);
                if (empty($line)) continue;

                if ($line[0] === NF_COMMENT) {
                    $this->parseCommentLine(substr($line, 1));
                    continue;
                }

                // Handle lines that start with "%-"
                if (substr($line, 0, 2) === '%-') {
                    // finalize old note, start a new one, etc.
                    // ...your note-handling code here...
                    continue;
                }

                // Handle lines that start with "%+"
                if (substr($line, 0, 2) === '%+') {
                    // finalize current section, start a new one
                    // ...your section-handling code here...
                    continue;
                }

                // Handle lines that start with "%:"
                if (substr($line, 0, 2) === '%:') {
                    // parse special note/metadata or anything you choose
                    // ...your code here...
                    continue;
                }

                // Handle lines that start with "%%"
                if (substr($line, 0, 2) === '%%') {
                    // parse another type of instruction
                    // ...your code here...
                    continue;
                }
 
                if ($line === NF_TabNote) {
                    $note = $this->readNote($handle);
                    if ($note) {
                        $this->notes[] = $note;
                    }
                    continue;
                }
                if ($line === NF_TreeNote) {
                    $note = $this->readTreeNote($handle);
                    if ($note) {
                        $this->notes[] = $note;
                    }
                    continue;
                }
                if ($line === NF_EOF) {
                    break;
                }
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    public function save($filename) {
        // Implement KeyNote file format writing
        // Based on the Pascal implementation in kn_FileObj.pas

        return true;
    }

    private function decryptFile($filename) {
        $data = file_get_contents($filename);
        if (!$data) {
            throw new Exception("Failed to read encrypted file");
        }

        $key = $this->deriveKey($this->passphrase);
        $iv = substr($data, 0, openssl_cipher_iv_length(CIPHER_METHOD));
        $ciphertext = substr($data, openssl_cipher_iv_length(CIPHER_METHOD));
        
        $decrypted = openssl_decrypt(
            $ciphertext,
            CIPHER_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($decrypted === false) {
            throw new Exception("Decryption failed: " . openssl_error_string());
        }
        
        return $decrypted;
    }

    private function encryptFile($filename) {
        $data = file_get_contents($filename);
        if (!$data) {
            throw new Exception("Failed to read file for encryption");
        }
        
        $key = $this->deriveKey($this->passphrase);
        $iv = random_bytes(openssl_cipher_iv_length(CIPHER_METHOD));
        
        $encrypted = openssl_encrypt(
            $data,
            CIPHER_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($encrypted === false) {
            throw new Exception("Encryption failed: " . openssl_error_string());
        }
        
        return $iv . $encrypted;
    }

    private function deriveKey($passphrase) {
        return hash_pbkdf2(
            HASH_ALGO,
            $passphrase,
            'keynote',
            10000, // Increased iterations for better security
            32,
            true
        );
    }

    private function padData($data) {
        $blockSize = CRYPT_BLOCK_SIZE;
        $padSize = $blockSize - (strlen($data) % $blockSize);
        return $data . str_repeat(chr($padSize), $padSize);
    }

    private function unpadData($data) {
        $padSize = ord($data[strlen($data) - 1]);
        return substr($data, 0, -$padSize);
    }

    private function parseCommentLine($line) {
        if (empty($line) || strlen($line) < 2) return;
        $identifier = $line[0];
        $content = trim(substr($line, 1));
        switch ($identifier) {
            case 'D': // Description
                $this->description = $content;
                break;
            case '/': // File comment 
                $this->description = $content;
                break;
            case '?': // Author info
                // Skip for now
                break;
            case '$': // Active note
                $this->activeNote = intval($content);
                break;
            case 'C': // Creation date
                try {
                    $this->dateCreated = \DateTime::createFromFormat('d-m-Y H:i:s', $content)->getTimestamp();
                } catch (\Exception $e) {
                    $this->dateCreated = time();
                }
                break;
            case '^': // File flags
                // TODO: Handle file flags
                break;
            case 'F': // Format settings
                // TODO: Handle format settings
                break;
        }
    }

    private function verboseLog($msg) {
        // Simple echo for demonstration
        echo "[KFile] $msg\n";
    }

    private function readNote($handle) {
        $notes = [];
        $currentNote = null;
        $currentSection = null;

        while (!feof($handle)) {
            $line = fgets($handle);
            if (!$line) break;
            $line = rtrim($line, "\r\n");

            // On each "%-" line, finalize the old note and start a new one
            if ($line === '%-') {
                // Finish previous note
                if ($currentNote) {
                    $this->verboseLog("Finished note: " . $currentNote->getName());
                    // Finish last section
                    if ($currentSection) {
                        $currentNote->addSection($currentSection);
                    }
                    $notes[] = $currentNote;
                }
                // Start new note
                $currentNote = new KNote();
                $currentSection = null;
                $this->verboseLog("Starting new note...");
                continue;
            }

            // On each "%+" line, finish the current section and start a new one
            if ($line === '%+') {
                if ($currentNote) {
                    if ($currentSection) {
                        $this->verboseLog("Finished section: " . ($currentSection['title'] ?? 'Untitled'));
                        $currentNote->addSection($currentSection);
                    }
                    $currentSection = ['title' => '', 'content' => ''];
                    $this->verboseLog("Starting new section...");
                }
                continue;
            }

            // Lines that start with "TT=" become the current title
            if (substr($line, 0, 3) === 'TT=') {
                $title = trim(substr($line, 3));
                $this->verboseLog("Found title: $title");
                if ($currentSection === null) {
                    // No section yet, treat as note title
                    if ($currentNote) {
                        $currentNote->parseMetadata('N' . $title);
                    }
                } else {
                    // If we are in a section, treat as section title
                    $currentSection['title'] = $title;
                }
                continue;
            }

            // Everything else is content
            if ($currentSection === null) {
                // Create the first section if none yet
                $currentSection = ['title' => '', 'content' => ''];
            }
            $currentSection['content'] .= $line . "\n";
        }

        // If the file doesn’t end with "%-", finalize the last note
        if ($currentNote) {
            if ($currentSection) {
                $currentNote->addSection($currentSection);
            }
            $notes[] = $currentNote;
        }
        // Return the array of notes or just the last one, depending on usage
        return end($notes);
    }

    private function readTreeNote($handle) {
        $treeNotes = [];
        $currentNote = null;
        $currentSection = null;
        // Similar approach: each "%-" starts a new note, each "%+" a new section
        // and lines that start "TT=" set note or section titles.
        
        while (!feof($handle)) {
            $line = fgets($handle);
            if (!$line) break;
            $line = rtrim($line, "\r\n");

            if ($line === '%-') {
                if ($currentNote) {
                    $this->verboseLog("Finished tree note: " . $currentNote->getName());
                    if ($currentSection) {
                        $currentNote->addSection($currentSection);
                    }
                    $treeNotes[] = $currentNote;
                }
                $currentNote = new KTreeNote();
                $currentSection = null;
                $this->verboseLog("Starting new tree note...");
                continue;
            }

            if ($line === '%+') {
                if ($currentNote) {
                    if ($currentSection) {
                        $this->verboseLog("Finished tree section: " . ($currentSection['title'] ?? 'Untitled'));
                        $currentNote->addSection($currentSection);
                    }
                    $currentSection = ['title' => '', 'content' => ''];
                    $this->verboseLog("Starting new tree section...");
                }
                continue;
            }

            if (substr($line, 0, 3) === 'TT=') {
                $title = trim(substr($line, 3));
                $this->verboseLog("Found tree note/section title: $title");
                if (!$currentSection) {
                    if ($currentNote) {
                        $currentNote->parseMetadata('N' . $title);
                    }
                } else {
                    $currentSection['title'] = $title;
                }
                continue;
            }

            // Accumulate content
            if ($currentSection === null) {
                $currentSection = ['title' => '', 'content' => ''];
            }
            $currentSection['content'] .= $line . "\n";
        }

        if ($currentNote) {
            if ($currentSection) {
                $currentNote->addSection($currentSection);
            }
            $treeNotes[] = $currentNote;
        }
        return end($treeNotes);
    }

    private function loadEncrypted($filename) {
        if (empty($this->passphrase)) {
            throw new Exception("Passphrase required for encrypted file");
        }

        $decrypted = $this->decryptFile($filename);
        
        // Create memory stream from decrypted data
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $decrypted);
        rewind($stream);
        
        try {
            // Parse decrypted content
            while (!feof($stream)) {
                $line = fgets($stream);
                if (!$line) continue;
                
                $line = trim($line);
                if (empty($line)) continue;

                if ($line[0] === NF_COMMENT) {
                    $this->parseCommentLine(substr($line, 1));
                    continue;
                }

                if ($line === NF_TabNote || $line === NF_TreeNote) {
                    $note = ($line === NF_TreeNote) ? 
                           $this->readTreeNote($stream) : 
                           $this->readNote($stream);
                    if ($note) {
                        $this->notes[] = $note;
                    }
                    continue;
                }

                if ($line === NF_EOF) {
                    break;
                }
            }
            return true;
        } finally {
            fclose($stream);
        }
    }

    // Add methods to read RTF content
    private function readRTFContent($handle) {
        $content = '';
        $braceCount = 0;
        $inRTF = false;
        
        while (!feof($handle)) {
            $line = fgets($handle);
            if (!$line) break;
            
            $line = rtrim($line, "\r\n");
            
            if (strpos($line, '{\rtf1') === 0) {
                $inRTF = true;
            }
            
            if ($inRTF) {
                $content .= $line . "\n";
                $braceCount += substr_count($line, '{');
                $braceCount -= substr_count($line, '}');
                
                if ($braceCount === 0 && strpos($line, '}') !== false) {
                    break;
                }
            }
        }
        
        return $content;
    }

    public function getNote($index) {
        return isset($this->notes[$index]) ? $this->notes[$index] : null;
    }

    public function getNoteCount() {
        return count($this->notes);
    }

    public function findNoteByName($name) {
        foreach ($this->notes as $note) {
            if (strcasecmp($note->getName(), $name) === 0) {
                return $note;
            }
        }
        return null;
    }

    // Add public getters
    public function getDescription() {
        return $this->description;
    }

    public function getComment() {
        return $this->comment;
    }

    public function getDateCreated() {
        return $this->dateCreated;
    }

    public function getActiveNote() {
        return $this->activeNote;
    }

    public function getFilename() {
        return $this->filename;
    }

    public function getFileFormat() {
        return $this->fileFormat;
    }

    public function isReadOnly() {
        return $this->readOnly;
    }
}

// Add KNote class for handling individual notes
class KNote {
    private $name;
    private $id;
    private $content;
    private $metadata;
    protected $rtfHeader;
    protected $isRTF;
    protected $level;
    protected $nodeName;
    private $sections;

    public function __construct() {
        $this->name = '';
        $this->id = 0;
        $this->content = '';
        $this->metadata = array();
        $this->rtfHeader = '{\rtf1\ansi\deff0\deflang1033}';
        $this->isRTF = false;
        $this->sections = array();
    }

    public function parseMetadata($line) {
        if (empty($line) || strlen($line) < 2) return;
        
        $identifier = $line[0];
        $content = trim(substr($line, 1));

        switch ($identifier) {
            case 'N': // Name
                $this->name = $content;
                break;
            case 'I': // ID
                $this->id = intval($content);
                break;
            // Add other metadata identifiers
        }
    }

    public function addContent($line) {
        if (strpos($line, '{\rtf1') === 0) {
            $this->isRTF = true;
        }
        $this->content .= $line . "\n";
    }

    public function getFormattedContent() {
        if ($this->isRTF) {
            return $this->content;
        }
        // Convert plain text to RTF
        $escaped = addslashes(str_replace("\n", '\par ', $this->content));
        return $this->rtfHeader . $escaped . '}';
    }

    public function getName() {
        return $this->name;
    }

    public function getId() {
        return $this->id;
    }

    public function getRawContent() {
        return $this->content;
    }

    public function setLevel($level) {
        $this->level = $level;
    }
    
    public function setNodeName($name) {
        $this->nodeName = $name;
    }
    
    public function getLevel() {
        return $this->level;
    }
    
    public function getNodeName() {
        return $this->nodeName;
    }

    public function addSection($content) {
        $this->sections[] = trim($content);
    }

    public function getSections() {
        return $this->sections; 
    }
}

// Add tree note support classes
class KTreeNote extends KNote {
    private $nodes;
    
    public function __construct() {
        parent::__construct();
        $this->nodes = array();
    }
    
    public function addNode(KTreeNode $node) {
        $this->nodes[] = $node;
    }

    public function getNodes() {
        return $this->nodes;
    }

    public function findNodeByName($name) {
        foreach ($this->nodes as $node) {
            if (strcasecmp($node->getName(), $name) === 0) {
                return $node;
            }
        }
        return null;
    }
}

class KTreeNode {
    private $name;
    private $content;
    private $metadata;
    private $parent;
    private $children;
    private $virtualMode;
    private $virtualSource;
    private $level;
    
    public function __construct() {
        $this->name = '';
        $this->content = '';
        $this->metadata = array();
        $this->children = array();
        $this->virtualMode = VirtualMode::vmNone;
        $this->virtualSource = '';
    }
    
    public function setParent($node) {
        $this->parent = $node;
    }
    
    public function addChild($node) {
        $this->children[] = $node;
    }
    
    public function setVirtualMode($mode) {
        $this->virtualMode = $mode;
    }
    
    public function parseMetadata($line) {
        if (empty($line) || strlen($line) < 2) return;
        
        $identifier = $line[0];
        $content = trim(substr($line, 1));
        
        switch ($identifier) {
            case 'N': // Name
                $this->name = $content;
                break;
            case 'V': // Virtual source
                $this->virtualSource = $content;
                break;
        }
    }
    
    public function addContent($line) {
        $this->content .= $line . "\n";
    }

    public function getName() {
        return $this->name;
    }

    public function getContent() {
        return $this->content;
    }

    public function getChildren() {
        return $this->children;
    }

    public function isVirtual() {
        return $this->virtualMode !== VirtualMode::vmNone;
    }

    public function getVirtualSource() {
        return $this->virtualSource;
    }

    public function setLevel($level) {
        $this->level = $level;
    }
    
    public function getLevel() {
        return $this->level;
    }
}

?>
