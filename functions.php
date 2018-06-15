<?php

function loadFile($filename)
{
    $file = fopen($filename, 'r');
    $i = 0;
    while (!feof($file)) {
        ++$i;
        $line = fgets($file);
        if ($line) {
            saveLine($i, $line, pathinfo($filename, PATHINFO_FILENAME));
        }
    }
    fclose($file);
}

function saveLine($lineNumber, $line, $file)
{
    $parts = explode("\t", $line, 2);

    if (count($parts) < 2) {
        throw new \Exception(sprintf('No tab found "%s:%s".', $file, $lineNumber));
    }

    list($voice, $text) = $parts;

    $voice = \Nette\Utils\Strings::trim($voice);
    $text = \Nette\Utils\Strings::trim($text);

    $rows = dibi::query('SELECT * FROM [voices] WHERE [voice] = %s AND [text] = %s', $voice, $text)->fetchAll();
    if ($rows) {
        return;
    }

    dibi::query('INSERT INTO [voices]', [
        'text' => $text,
        'voice' => $voice,
        'file' => $file,
        'line' => $lineNumber,
    ]);
}

function processFile($filename)
{
    $file = fopen($filename, 'r');
    @unlink($filename . '.new');
    $output = fopen($filename . '.new', 'w');
    $processor = lineProcessor();
    while (!feof($file)) {
        $line = fgets($file);
        $voiceLine = $processor->send($line);
        if ($voiceLine) {
            fwrite($output, $voiceLine);
        }
        fwrite($output, $line);
    }
    fclose($file);
    fclose($output);
    unlink($filename);
    rename($filename . '.new', $filename);

    $processor->send(null);
    list($inserted, $placeholders, $longQuotes) = $processor->getReturn();
    echo "Done with file " . pathinfo($filename, PATHINFO_BASENAME) . ".\n";
    echo "- inserted $inserted voice lines\n";
    echo "- inserted $placeholders placeholders\n";
    echo "- found $longQuotes long quotes\n";
}

function lineProcessor(): Generator
{
    $inserted = 0;
    $placeholders = 0;
    $longQuotes = 0;
    $i = 0;
    $lastVoice = null;
    $quote = false;
    $linesSinceQuote = 0;
    $voiceLine = null;
    $matcher = new VoiceMatcher();

    while (is_string($line = yield $voiceLine)) {
        $voiceLine = null;

        ++$i;
        if (strpos($line, 'OutputLine(') !== false) {
            if ($match = \Nette\Utils\Strings::match($line, '~^\\s++OutputLine\\(NULL,\\s++"([^"]++)"~')) {
                $text = \Nette\Utils\Strings::trim($match[1]);

                if (\Nette\Utils\Strings::startsWith($text, '「')) {
                    $quote = true;
                    $linesSinceQuote = 0;
                }

                ++$linesSinceQuote;

                $voice = $matcher->findVoice($text, $quote);
                if ($voice && $lastVoice !== $voice) {
                    ++$inserted;
                    $voiceLine = "\tPlaySE(4, \"$voice\", 128, 64);\n";
                    $lastVoice = $voice;
                } elseif ($quote) {
                    ++$placeholders;
                    $voiceLine = "\tPlaySE(4, \"\", 128, 64);\n";

                    // If a quote is too long assume the ending quote is missing.
                    if ($linesSinceQuote >= 10) {
                        //$voiceLine .= "\t// long quote\n";
                        $linesSinceQuote = 0;
                        $quote = false;
                        ++$longQuotes;
                    }
                }

                if (\Nette\Utils\Strings::endsWith($text, '」')) {
                    $quote = false;
                }
            }
        }
    }

    return [$inserted, $placeholders, $longQuotes];
}

class VoiceMatcher
{
    private $lastFile = null;

    public function findVoice($text, $quote)
    {
        if (! $quote) {
            return;
        }

        $text = strtr($text, [
            '〜' => '～',
        ]);

        if ($match = $this->searchNormal($text)) {
            return $match;
        }

        if ($match = $this->removeDot($text)) {
            return $match;
        }

        if ($match = $this->removeDotBeforeQuote($text)) {
            return $match;
        }

        if ($match = $this->searchStart($text)) {
            return $match;
        }

        if ($match = $this->searchLevenshteinWithMatchigMiddle($text)) {
            return $match;
        }

        if ($match = $this->searchLevenshteinWithMatchingPrefix($text)) {
            return $match;
        }
    }

    private function searchNormal($text)
    {
        $rows = dibi::query('SELECT * FROM [voices] WHERE [text] = %s', $text)->fetchAll();
        if (count($rows) === 1) {
            $this->lastFile = $rows[0]['file'];
            return $rows[0]['voice'];
        }

        if (!$this->lastFile) {
            return;
        }

        $rows = dibi::query('SELECT * FROM [voices] WHERE [text] = %s AND file = %s', $text, $this->lastFile)->fetchAll();
        if (count($rows) === 1) {
            return $rows[0]['voice'];
        }
    }

    private function searchStart($text)
    {
        if (\Nette\Utils\Strings::length($text) < 5) {
            return;
        }

        $rows = dibi::query('SELECT * FROM [voices] WHERE [text] LIKE %s OR [text] LIKE %s', $text . '%', '「' . $text . '%')->fetchAll();
        if (count($rows) === 1) {
            $this->lastFile = $rows[0]['file'];
            return $rows[0]['voice'];
        }

        if (!$this->lastFile) {
            return;
        }

        $rows = dibi::query('SELECT * FROM [voices] WHERE ([text] LIKE %s OR [text] LIKE %s) AND file = %s', '「' . $text . '%', $text . '%', $this->lastFile)->fetchAll();
        if (count($rows) === 1) {
            return $rows[0]['voice'];
        }
    }

    private function searchLevenshteinWithMatchigMiddle($text)
    {
        $cut = round(\Nette\Utils\Strings::length($text) / 5);
        if ($cut <= 1) {
            return;
        }
        $rows = dibi::query('SELECT * FROM [voices] WHERE [text] LIKE %s AND levenshtein_ratio([text], %s) >= 90', '%' . \Nette\Utils\Strings::subString($text, $cut, -$cut) . '%', $text)->fetchAll();
        if (count($rows) === 1) {
            $this->lastFile = $rows[0]['file'];
            return $rows[0]['voice'];
        }

        if (!$this->lastFile) {
            return;
        }

        $rows = dibi::query('SELECT * FROM [voices] WHERE [file] = %s AND [text] LIKE %s AND levenshtein_ratio([text], %s) >= 90', $this->lastFile, '%' . \Nette\Utils\Strings::subString($text, $cut, -$cut) . '%', $text)->fetchAll();
        if (count($rows) === 1) {
            return $rows[0]['voice'];
        }
    }

    private function searchLevenshteinWithMatchingPrefix($text)
    {
        $cut = 3;
        if (!$this->lastFile) {
            return;
        }

        $rows = dibi::query('SELECT * FROM [voices] WHERE [file] = %s AND [text] LIKE %s AND levenshtein_ratio([text], %s) >= 85', $this->lastFile, \Nette\Utils\Strings::subString($text, 0, $cut) . '%', $text)->fetchAll();
        if (count($rows) === 1) {
            return $rows[0]['voice'];
        }
    }

    private function removeDot($text)
    {
        if (!\Nette\Utils\Strings::endsWith($text, '。')) {
            return;
        }

        $text = \Nette\Utils\Strings::subString($text, 0, - \Nette\Utils\Strings::length('。'));

        $rows = dibi::query('SELECT * FROM [voices] WHERE [text] = %s', $text)->fetchAll();
        if (count($rows) === 1) {
            $this->lastFile = $rows[0]['file'];
            return $rows[0]['voice'];
        }

        if (!$this->lastFile) {
            return;
        }

        $rows = dibi::query('SELECT * FROM [voices] WHERE [text] = %s AND file = %s', $text, $this->lastFile)->fetchAll();
        if (count($rows) === 1) {
            return $rows[0]['voice'];
        }
    }

    private function removeDotBeforeQuote($text)
    {
        if (!\Nette\Utils\Strings::endsWith($text, '。」')) {
            return;
        }

        $text = \Nette\Utils\Strings::subString($text, 0, - \Nette\Utils\Strings::length('。」')) . '」';

        $rows = dibi::query('SELECT * FROM [voices] WHERE [text] = %s', $text)->fetchAll();
        if (count($rows) === 1) {
            $this->lastFile = $rows[0]['file'];
            return $rows[0]['voice'];
        }

        if (!$this->lastFile) {
            return;
        }

        $rows = dibi::query('SELECT * FROM [voices] WHERE [text] = %s AND file = %s', $text, $this->lastFile)->fetchAll();
        if (count($rows) === 1) {
            return $rows[0]['voice'];
        }
    }
}
