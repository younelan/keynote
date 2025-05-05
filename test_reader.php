<?php

require_once 'Kfile.php';

function printNode($node, $level = 0) {
    $indent = str_repeat('  ', $level);
    echo $indent . "- Node: " . $node->getName() . "\n";
    
    if ($node->isVirtual()) {
        echo $indent . "  (Virtual Node linked to: " . $node->getVirtualSource() . ")\n";
    }
    
    foreach ($node->getChildren() as $child) {
        printNode($child, $level + 1);
    }
}

function printNote($note, $index) {
    echo "\nNote #$index:\n";
    echo "Name: " . $note->getName() . "\n";
    echo "ID: " . $note->getId() . "\n";
    
    if ($note instanceof KTreeNote) {
        echo "Type: Tree Note\n";
        echo "Structure:\n";
        foreach ($note->getNodes() as $node) {
            printNode($node);
        }
    } else {
        echo "Type: RTF Note\n";
        echo "Has RTF Content: " . ($note->isRTF ? "Yes" : "No") . "\n";
    }
    echo "----------------------------------------\n";
}

// Main execution
try {
    if ($argc < 2) {
        die("Usage: php test_reader.php <path_to_keynote_file>\n");
    }

    $filename = $argv[1];
    $kfile = new KFile();
    
    echo "Loading KeyNote file: $filename\n";
    $kfile->load($filename);
    
    echo "\nFile Information:\n";
    echo "Description: " . $kfile->getDescription() . "\n";
    echo "Comment: " . $kfile->getComment() . "\n";
    echo "Date Created: " . date('Y-m-d H:i:s', $kfile->getDateCreated()) . "\n";
    echo "Number of Notes: " . $kfile->getNoteCount() . "\n";
    echo "Active Note Index: " . $kfile->getActiveNote() . "\n";
    echo "----------------------------------------\n";
    
    for ($i = 0; $i < $kfile->getNoteCount(); $i++) {
        $note = $kfile->getNote($i);
print "hi";
        printNote($note, $i + 1);
    }

} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

?>
