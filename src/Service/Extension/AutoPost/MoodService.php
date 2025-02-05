<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost;

use App\Common\DTO\MoodConfiguration;

class MoodService
{
    /**
     * @var MoodConfiguration[]
     */
    private array $moodConfigurations = [];

    private const MOODS_FILE = __DIR__ . '/../../../config/moods.json';

    public function __construct()
    {
        $this->loadMoodsFromFile();
    }

    /**
     * Loads moods from the JSON file.
     */
    private function loadMoodsFromFile(): void
    {
        if (file_exists(self::MOODS_FILE)) {
            $json = file_get_contents(self::MOODS_FILE);
            $data = json_decode($json, true);
            if (is_array($data)) {
                $this->moodConfigurations = [];
                foreach ($data as $item) {
                    if (
                        isset($item['name'], $item['modifier'], $item['temperature'], $item['chance'])
                    ) {
                        $this->moodConfigurations[] = new MoodConfiguration(
                            $item['name'],
                            $item['modifier'],
                            (float)$item['temperature'],
                            (int)$item['chance']
                        );
                    }
                }
            }
        } else {
            $this->moodConfigurations = $this->getDefaultMoods();
            $this->saveMoodsToFile();
        }
    }

    /**
     * Persists the current moods to the JSON file.
     */
    private function saveMoodsToFile(): void
    {
        $data = [];
        foreach ($this->moodConfigurations as $mood) {
            $data[] = [
                'name' => $mood->getName(),
                'modifier' => $mood->getModifier(),
                'temperature' => $mood->getTemperature(),
                'chance' => $mood->getChance(),
            ];
        }
        file_put_contents(self::MOODS_FILE, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Returns the default mood configurations.
     *
     * @return MoodConfiguration[]
     */
    private function getDefaultMoods(): array
    {
        return [
            new MoodConfiguration(
                'shitpost',
                "Ein extrem kurzer, persönlicher und belangloser Ton. Der Inhalt soll absichtlich unsinnig, oberflächlich und irrelevant wirken – ein lockerer, frecher Stil ohne Anspruch auf tiefgehende Information oder Analyse.",
                0.9,
                15
            ),
            new MoodConfiguration(
                'politisch-engagiert',
                "Ein prägnanter, formeller Ton, der tiefgehende Analysen aktueller politischer Ereignisse liefert – sachlich, informativ und pointiert. Direkt, fesselnd und meinungsstark, um Debatten anzuregen und maximale Reichweite zu erzielen. Ohne Emojis oder unnötige Ausschmückungen, stattdessen mit klarer Haltung und scharfem Fokus auf das Wesentliche.",
                0.6,
                20
            ),
            new MoodConfiguration(
                'persönlich-emotional',
                "Persönlich, emotional und mit einer Prise Humor – ein lebendiger, nahbarer Stil, der intime Erlebnisse im Kontext politischer Themen erzählt. Authentisch, direkt und mit einer individuellen Note, geprägt von regionalem Ausdruck, spontaner Satzstruktur und bewusst spielerischer Grammatik. Emojis gezielt eingesetzt, um Emotionen zu verstärken und Nähe zu schaffen – ein Beitrag, der nicht nur informiert, sondern berührt und Gesprächsstoff liefert.",
                0.8,
                15
            ),
            new MoodConfiguration(
                'emotional-sarkastisch',
                "Bissig, unverschämt und mit maximaler Sprengkraft – ein kreativer, persönlicher Beitrag, der brandaktuelle Inside-Jokes und Memes aus der links-antikapitalistischen und marxistischen deutschen Bubble in gnadenlosen Sarkasmus packt. Radikal, respektlos und mit rabenschwarzem Humor formuliert, der die Grenzen des Sagbaren testet und maximal provoziert. Kein Hashtag-Safety-Net, nur scharfe Pointen, die treffen, wo es weh tut – ein Beitrag, der polarisiert, triggert und für hitzige Debatten sorgt.",
                1.0,
                10
            ),
            new MoodConfiguration(
                'politisch-informativ',
                "Enthüllend, bissig und gnadenlos treffend – ein kreativer, humorvoller Beitrag, der die absurdesten, widersprüchlichsten und kaum bekannten Funfacts aus der deutschen Politiklandschaft ans Licht zerrt. Ohne Hashtags, dafür mit maximaler Provokation und schwarzem Humor, der zwischen staubtrockener Ironie und ungefiltertem Zynismus balanciert. Ein Beitrag, der Unwissenheit sprengt, Narrative aufbricht und garantiert dafür sorgt, dass man sich fragt: Warum zur Hölle wusste ich das nicht schon früher?",
                0.7,
                20
            ),
            new MoodConfiguration(
                'ausgewogen',
                "Reflektiert, nahbar und authentisch – ein ausgewogener Beitrag, der politische Themen mit persönlichen Einblicken verbindet. Mit einem sachlichen, aber lebendigen Ton, der komplexe Zusammenhänge verständlich macht, ohne den eigenen Standpunkt zu verstecken. Moderat lang, mit gezielt eingesetzten Emojis für Leichtigkeit und Persönlichkeit, ohne in Extreme zu verfallen. Ein Beitrag, der informiert, zum Nachdenken anregt und gleichzeitig Raum für individuelle Perspektiven lässt.",
                0.5,
                20
            ),
        ];
    }

    /**
     * Chooses one mood based on random chance.
     *
     * @return array{mood: string, modifier: string, temperature: float}
     */
    public function chooseMood(): array
    {
        $totalChance = 1;
        foreach ($this->moodConfigurations as $mood) {
            $totalChance += $mood->getChance();
        }
        if ($totalChance < 1) {
            throw new \RuntimeException('Total chance is less than 1.');
        }
        $rand = random_int(1, $totalChance);
        foreach ($this->moodConfigurations as $mood) {
            if ($rand <= $mood->getChance()) {
                return [
                    'mood' => $mood->getName(),
                    'modifier' => $mood->getModifier(),
                    'temperature' => $mood->getTemperature(),
                ];
            }
            $rand -= $mood->getChance();
        }
        $last = end($this->moodConfigurations);
        return [
            'mood' => $last->getName(),
            'modifier' => $last->getModifier(),
            'temperature' => $last->getTemperature(),
        ];
    }

    /**
     * Returns all mood configurations.
     *
     * @return MoodConfiguration[]
     */
    public function getMoods(): array
    {
        return $this->moodConfigurations;
    }

    /**
     * Replaces the current moods and persists them.
     *
     * @param MoodConfiguration[] $moods
     */
    public function setMoods(array $moods): void
    {
        $this->moodConfigurations = $moods;
        $this->saveMoodsToFile();
    }

    /**
     * Adds a mood configuration and persists the change.
     *
     * @param MoodConfiguration $moodConfiguration
     */
    public function addMood(MoodConfiguration $moodConfiguration): void
    {
        foreach ($this->moodConfigurations as $mood) {
            if ($mood->getName() === $moodConfiguration->getName()) {
                return;
            }
        }
        $this->moodConfigurations[] = $moodConfiguration;
        $this->saveMoodsToFile();
    }

    /**
     * Removes a mood configuration by name and persists the change.
     *
     * @param string $moodName
     */
    public function removeMood(string $moodName): void
    {
        foreach ($this->moodConfigurations as $key => $mood) {
            if ($mood->getName() === $moodName) {
                unset($this->moodConfigurations[$key]);
                $this->moodConfigurations = array_values($this->moodConfigurations);
                $this->saveMoodsToFile();
                return;
            }
        }
    }

    // The remaining methods (getTimeReference, getConsistencyThreshold) remain unchanged.
    public function getTimeReference(string $currentMood): string
    {
        if (str_contains($currentMood, 'emotional') && random_int(1, 100) <= 20) {
            $hour = (int)(new \DateTime())->format('H');
            if ($hour < 7) {
                return "Frühmorgens";
            }
            if ($hour < 9) {
                return "Morgens";
            }
            if ($hour < 20) {
                return "Spätnachmittags";
            }
            return "Nachts";
        }
        return "";
    }

    public function getConsistencyThreshold(?string $previousMood, string $currentMood): int
    {
        return ($previousMood !== null && $previousMood !== $currentMood) ? 50 : 70;
    }
}
