<?php

class NoteFileException extends Exception {}
class PassphraseException extends Exception {}

class NoteFile {
    private $fileName;
    private $fileFormat;
    private $readOnly = false;
    private $activeNote;
    private $dateCreated;
    private $description;
    private $comment;
    private $notes = [];
    
    // Additional properties
    private $bookmarks = [];
    private $version = [
        'id' => '',
        'major' => '',
        'minor' => ''
    ];
    private $passphrase = '';
    private $cryptMethod = '';
    private $openAsReadOnly = false;
    private $showTabIcons = true;
    private $noMultiBackup = false;
    private $clipCapNote = null;
    private $savedWithRichEdit3 = false;
    private $virtualNodes = [];

    const NFHDR_ID = 'GFKNT';
    const NFHDR_ID_OLD = 'GFKNX'; 
    const NFHDR_ID_ENCRYPTED = 'GFKNE';
    const VERSION_MAJOR = '2';
    const VERSION_MINOR = '0';
    const MAX_BOOKMARKS = 9;

    // Flag constants for parsing
    const FLAG_READONLY = 0;
    const FLAG_SHOW_ICONS = 1;
    const FLAG_RICHEDIT3 = 2;
    const FLAG_NO_MULTIBACKUP = 3;

    const FILE_FORMATS = [
        'keynote' => 'KeyNote',
        'encrypted' => 'Encrypted',
        'dartnotes' => 'DartNotes' 
    ];

    public function load($fileName) {
        $this->fileName = $fileName;
        if (!file_exists($fileName)) {
            throw new Exception("Cannot open \"$fileName\": File not found");
        }
        
        $this->readOnly = !is_writable($fileName);
        
        $handle = fopen($fileName, 'r');
        if (!$handle) {
            throw new Exception("Failed to open file: $fileName");
        }

        // First detect file format
        $testString = fread($handle, 12);
        $format = $this->detectFileFormat($testString);
        
        if (!$format) {
            throw new NoteFileException("Invalid file format");
        }

        $this->fileFormat = $format;

        // Handle encrypted files
        if ($format === self::FILE_FORMATS['encrypted']) {
            return $this->loadEncryptedFile($fileName);
        }

        // Version check
        if (!$this->checkFileVersion($testString)) {
            throw new NoteFileException("Incompatible file version");
        }

        // Parse file based on format
        switch($format) {
            case self::FILE_FORMATS['keynote']:
                $this->loadKeyNoteFormat($handle);
                break;
            case self::FILE_FORMATS['dartnotes']:
                $this->loadDartNotesFormat($handle);
                break;
        }

        $this->verifyNoteIds();
        return true;
    }

    protected function loadEncryptedFile($fileName) {
        if (empty($this->passphrase)) {
            throw new PassphraseException("Passphrase required for encrypted file");
        }

        // Decrypt file to memory stream
        $decrypted = $this->decryptFile($fileName);
        
        // Parse decrypted content
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $decrypted);
        rewind($handle);

        $this->loadKeyNoteFormat($handle);
        return true;
    }

protected function loadKeyNoteFormat($handle) {
    $inHead = true;
    $currentNote = null;
    $inContent = false;
    $inProperties = false;
    $content = '';
    $inRTF = false;
    $rtfDepth = 0;

    while (!feof($handle)) {
        $line = fgets($handle);
        if ($line === false) break;
        
        $line = rtrim($line);
        if (empty($line) && !$inRTF) continue;

        // Parse file header metadata 
        if ($inHead && $line[0] === '#') {
            // ...existing header parsing code...
            continue;
        }

        // Handle section markers
        if ($line === '%+') { // Start new section
            $inHead = false;
            $inContent = false;
            $inProperties = true;
            $inRTF = false;
            
            if ($currentNote) {
                $currentNote['content'] = $content;
                $this->notes[] = $currentNote;
            }

            $currentNote = [
                'type' => 'rtf',
                'name' => '',
                'id' => 0,
                'content' => '',
                'properties' => [],
                'level' => 0
            ];
            $content = '';
            continue;
        }
        else if ($line === '%-') { // Properties section
            $inProperties = true;
            $inContent = false;
            continue;
        }
        else if ($line === '%:') { // Content section
            $inProperties = false; 
            $inContent = true;
            $content = '';
            continue;
        }
        else if ($line === '%%') { // End of file
            if ($currentNote) {
                $currentNote['content'] = $content;
                $this->notes[] = $currentNote;
            }
            break;
        }

        // Parse content
        if ($currentNote) {
            if ($inContent) {
                // Track RTF content
                if (strpos($line, '{\rtf') === 0) {
                    $inRTF = true;
                    $rtfDepth = 1;
                }

                if ($inRTF) {
                    // Count nested braces
                    $rtfDepth += substr_count($line, '{') - substr_count($line, '}');
                    $content .= $line . "\n";
                    
                    // End of RTF when all braces are closed
                    if ($rtfDepth === 0) {
                        $inRTF = false;
                        $inContent = false;
                        $inProperties = true;
                    }
                }
            }
            else if ($inProperties) {
                // Parse note properties
                if (strpos($line, 'ND=') === 0) {
                    $currentNote['name'] = substr($line, 3);
                }
                else if (strpos($line, 'ID=') === 0) {
                    $currentNote['id'] = intval(substr($line, 3));
                }
                else if (strpos($line, 'LV=') === 0) {
                    $currentNote['level'] = intval(substr($line, 3));
                }
                else if (strpos($line, 'DC=') === 0) {
                    $currentNote['properties']['created'] = substr($line, 3);
                }
                else if (strpos($line, 'FL=') === 0) {
                    $currentNote['properties']['flags'] = substr($line, 3);
                }
                // ...other properties...
            }
        }
    }
}

public function displayNotes($notes = null, $level = 0) {
    if ($notes === null) {
        $notes = $this->notes;
    }

    foreach ($notes as $i => $note) {
        echo "\n" . str_repeat("  ", $level);
        echo "=== Note " . ($i + 1) . " (Level " . $note['level'] . ") ===\n";
        echo str_repeat("  ", $level) . "Name: " . $note['name'] . "\n";
        
        if (!empty($note['properties'])) {
            echo str_repeat("  ", $level) . "Properties:\n";
            foreach ($note['properties'] as $key => $value) {
                echo str_repeat("  ", $level) . "  $key: $value\n"; 
            }
        }

        if (!empty($note['content'])) {
            echo "\nContent:\n" . $this->stripRTF($note['content']);
        }

        // Display child notes within this section
        if (!empty($note['notes'])) {
            $this->displayNotes($note['notes'], $level + 1);
        }
    }
}

    protected function parseNote($handle) {
        $note = [
            'type' => 'rtf',
            'name' => '',
            'id' => 0,
            'content' => '',
            'properties' => []
        ];

        $inContent = false;
        $content = '';

        while (!feof($handle)) {
            $line = fgets($handle);
            $line = rtrim($line);

            if ($line === '%-') { 
                $inContent = false;
                continue;
            }
            else if ($line === '%:') {
                $inContent = true;
                continue;
            }
            else if ($line === '%+' || $line === '%%') {
                // Save current note
                $note['content'] = $content;
                $this->notes[] = $note;
                break;
            }

            if ($inContent) {
                $content .= $line . "\n";
            }
            else {
                // Parse note properties
                // ...existing property parsing code...
            }
        }
    }

    protected function decryptFile($fileName) {
        // Implementation of Blowfish/IDEA decryption
        // This would require additional crypto libraries
    }

    public function hasVirtualNodes() {
        foreach ($this->notes as $note) {
            if (!empty($note['virtual_nodes'])) {
                return true;
            }
        }
        return false;
    }

    public function getNoteById($id) {
        foreach ($this->notes as $note) {
            if ($note['id'] === $id) {
                return $note;
            }
        }
        return null;
    }

    // Add bookmark support
    public function setBookmark($index, $name, $noteId, $position) {
        if ($index >= 0 && $index <= self::MAX_BOOKMARKS) {
            $this->bookmarks[$index] = [
                'name' => $name,
                'note_id' => $noteId,
                'position' => $position
            ];
        }
    }

    public function getBookmark($index) {
        return isset($this->bookmarks[$index]) ? 
               $this->bookmarks[$index] : null;
    }

    public function clearBookmarks() {
        $this->bookmarks = [];
    }

    // Additional helper methods...
    protected function detectFileFormat($header) {
        if (strpos($header, self::NFHDR_ID) !== false) {
            return self::FILE_FORMATS['keynote'];
        }
        if (strpos($header, self::NFHDR_ID_ENCRYPTED) !== false) {
            return self::FILE_FORMATS['encrypted'];
        }
        return null;
    }

    protected function checkFileVersion($header) {
        // Extract and validate version information
        preg_match('/(\d+)\.(\d+)/', $header, $matches);
        if (count($matches) === 3) {
            $this->version['major'] = $matches[1];
            $this->version['minor'] = $matches[2];
            
            // Version compatibility check
            if ($this->version['major'] > self::VERSION_MAJOR) {
                return false;
            }
        }
        return true;
    }

    public function getNotes() {
        return $this->notes;
    }

    private function stripRTF($rtf) {
        // Basic RTF stripping - you may need to enhance this
        $pattern = "/\{[^\}]*\}|[\\].|[\\][^\s]+ |[{}]|\r\n|\n/";
        $text = preg_replace($pattern, "", $rtf);
        return trim($text);
    }

    private function parseDate($dateStr) {
        // Parse KeyNote date format
        $parts = explode(' ', $dateStr);
        if (count($parts) === 2) {
            return strtotime($dateStr);
        }
        return time();
    }

    protected function parseFlags($flagString) {
        if (strlen($flagString) < 4) return; // Minimum 4 flags required
        
        $this->openAsReadOnly = ($flagString[self::FLAG_READONLY] === '1');
        $this->showTabIcons = ($flagString[self::FLAG_SHOW_ICONS] === '1');
        $this->savedWithRichEdit3 = ($flagString[self::FLAG_RICHEDIT3] === '1');
        $this->noMultiBackup = ($flagString[self::FLAG_NO_MULTIBACKUP] === '1');

        if ($this->openAsReadOnly) {
            $this->readOnly = true;
        }
    }

    protected function verifyNoteIds() {
        $count = count($this->notes);
        $highestId = 0;

        // First find highest existing ID
        foreach($this->notes as $note) {
            if ($note['id'] > $highestId) {
                $highestId = $note['id'];
            }
        }

        // Assign new IDs to notes without IDs
        foreach($this->notes as &$note) {
            if (empty($note['id']) || $note['id'] <= 0) {
                $highestId++;
                $note['id'] = $highestId;
            }
        }
    }

    protected function generateNoteId($note) {
        $highestId = 0;
        
        // Find highest existing ID
        foreach($this->notes as $existingNote) {
            if ($existingNote['id'] > $highestId) {
                $highestId = $existingNote['id'];
            }
        }

        // Assign next highest ID
        $note['id'] = $highestId + 1;
        return $note['id'];
    }

    public function addNote($note) {
        if (!isset($note['id']) || $note['id'] <= 0) {
            $this->generateNoteId($note);
        }
        $this->notes[] = $note;
        $this->modified = true;
        return count($this->notes) - 1; // Return index of added note
    }

    public function deleteNote($noteId) {
        foreach($this->notes as $index => $note) {
            if ($note['id'] === $noteId) {
                array_splice($this->notes, $index, 1);
                $this->modified = true;
                return true;
            }
        }
        return false;
    }

    public function hasExtendedNotes() {
        foreach($this->notes as $note) {
            if ($note['type'] !== 'rtf') {
                return true;
            }
        }
        return false;
    }

    public function hasVirtualNodeByFileName($noteNode, $fileName) {
        foreach($this->notes as $note) {
            if ($note['type'] === 'tree' && !empty($note['nodes'])) {
                foreach($note['nodes'] as $node) {
                    if (!empty($node['virtual_mode']) && 
                        $node['virtual_fn'] === $fileName && 
                        $node !== $noteNode) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    protected function loadDartNotesFormat($handle) {
        $header = [
            'id' => '',
            'ver' => '',
            'lastTabIdx' => 0
        ];

        // Read DartNotes header
        $blockLen = intval(fgets($handle));
        $headerData = fread($handle, $blockLen);
        
        if (strpos($headerData, '_DART_ID') === false) {
            throw new NoteFileException("Invalid DartNotes header");
        }

        // Parse header
        $parts = explode("\0", $headerData);
        if (count($parts) >= 4) {
            $header['id'] = $parts[0];
            $header['ver'] = $parts[1];
            $header['lastTabIdx'] = intval($parts[3]);
        }

        $this->activeNote = $header['lastTabIdx'];
        
        // Read notes until EOF
        while (!feof($handle)) {
            $note = $this->parseDartNote($handle);
            if ($note) {
                $this->notes[] = $note;
            }
        }
    }

    protected function parseDartNote($handle) {
        $note = [
            'type' => 'rtf',
            'name' => '',
            'id' => 0,
            'content' => '',
            'properties' => []
        ];

        // Read note header block
        $blockLen = intval(fgets($handle));
        if ($blockLen <= 0) return null;

        $headerData = fread($handle, $blockLen);
        $parts = explode("\0", $headerData);

        if (count($parts) >= 2) {
            $note['name'] = $parts[0];
            $note['properties']['created'] = $parts[1];
        }

        // Read note content block
        $blockLen = intval(fgets($handle));
        if ($blockLen > 0) {
            $note['content'] = fread($handle, $blockLen);
        }

        return $note;
    }
}

// Example usage
$noteFile = new NoteFile();
try {
    $noteFile->load('notes.knt');
    echo "File loaded successfully!\n";
    $noteFile->displayNotes();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}


