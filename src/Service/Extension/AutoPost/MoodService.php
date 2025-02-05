<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost;

class MoodService
{
    /**
     * Default mood configurations.
     *
     * Each mood is keyed by its name with a corresponding modifier and temperature.
     *
     * @var array<string, array{modifier: string, temperature: float}>
     */
    private array $moodConfigurations = [
        'shitpost' => [
            'modifier' => "Ein extrem kurzer, persönlicher und belangloser Ton. Der Inhalt soll absichtlich unsinnig, oberflächlich und irrelevant wirken – ein lockerer, frecher Stil ohne Anspruch auf tiefgehende Information oder Analyse.",
            'temperature' => 0.9,
        ],
        'politisch-engagiert' => [
            'modifier' => "Ein prägnanter, formeller Ton, der tiefgehende Analysen aktueller politischer Ereignisse liefert – sachlich, informativ und pointiert. Direkt, fesselnd und meinungsstark, um Debatten anzuregen und maximale Reichweite zu erzielen. Ohne Emojis oder unnötige Ausschmückungen, stattdessen mit klarer Haltung und scharfem Fokus auf das Wesentliche.",
            'temperature' => 0.6,
        ],
        'persönlich-emotional' => [
            'modifier' => "Persönlich, emotional und mit einer Prise Humor – ein lebendiger, nahbarer Stil, der intime Erlebnisse im Kontext politischer Themen erzählt. Authentisch, direkt und mit einer individuellen Note, geprägt von regionalem Ausdruck, spontaner Satzstruktur und bewusst spielerischer Grammatik. Emojis gezielt eingesetzt, um Emotionen zu verstärken und Nähe zu schaffen – ein Beitrag, der nicht nur informiert, sondern berührt und Gesprächsstoff liefert.",
            'temperature' => 0.8,
        ],
        'emotional-sarkastisch' => [
            'modifier' => "Bissig, unverschämt und mit maximaler Sprengkraft – ein kreativer, persönlicher Beitrag, der brandaktuelle Inside-Jokes und Memes aus der links-antikapitalistischen und marxistischen deutschen Bubble in gnadenlosen Sarkasmus packt. Radikal, respektlos und mit rabenschwarzem Humor formuliert, der die Grenzen des Sagbaren testet und maximal provoziert. Kein Hashtag-Safety-Net, nur scharfe Pointen, die treffen, wo es weh tut – ein Beitrag, der polarisiert, triggert und für hitzige Debatten sorgt.",
            'temperature' => 1.0,
        ],
        'politisch-informativ' => [
            'modifier' => "Enthüllend, bissig und gnadenlos treffend – ein kreativer, humorvoller Beitrag, der die absurdesten, widersprüchlichsten und kaum bekannten Funfacts aus der deutschen Politiklandschaft ans Licht zerrt. Ohne Hashtags, dafür mit maximaler Provokation und schwarzem Humor, der zwischen staubtrockener Ironie und ungefiltertem Zynismus balanciert. Ein Beitrag, der Unwissenheit sprengt, Narrative aufbricht und garantiert dafür sorgt, dass man sich fragt: Warum zur Hölle wusste ich das nicht schon früher?",
            'temperature' => 0.7,
        ],
        'ausgewogen' => [
            'modifier' => "Reflektiert, nahbar und authentisch – ein ausgewogener Beitrag, der politische Themen mit persönlichen Einblicken verbindet. Mit einem sachlichen, aber lebendigen Ton, der komplexe Zusammenhänge verständlich macht, ohne den eigenen Standpunkt zu verstecken. Moderat lang, mit gezielt eingesetzten Emojis für Leichtigkeit und Persönlichkeit, ohne in Extreme zu verfallen. Ein Beitrag, der informiert, zum Nachdenken anregt und gleichzeitig Raum für individuelle Perspektiven lässt.",
            'temperature' => 0.5,
        ],
    ];

    /**
     * Chooses one of several moods based on random chance and returns an array
     * with the keys 'mood', 'modifier' and 'temperature'.
     *
     * This method’s internal logic remains unchanged.
     *
     * @return array{mood: string, modifier: string, temperature: float}
     */
    public function chooseMood(): array
    {
        $rand = random_int(1, 100);

        if ($rand <= 15) { // 15%: Shitpost
            return [
                'mood' => 'shitpost',
                'modifier' => $this->moodConfigurations['shitpost']['modifier'],
                'temperature' => $this->moodConfigurations['shitpost']['temperature'],
            ];
        }

        if ($rand <= 35) { // 20% (15 < rand <= 35): Politisch-engagiert
            return [
                'mood' => 'politisch-engagiert',
                'modifier' => $this->moodConfigurations['politisch-engagiert']['modifier'],
                'temperature' => $this->moodConfigurations['politisch-engagiert']['temperature'],
            ];
        }

        if ($rand <= 50) { // 15% (35 < rand <= 50): Persönlich-emotional
            return [
                'mood' => 'persönlich-emotional',
                'modifier' => $this->moodConfigurations['persönlich-emotional']['modifier'],
                'temperature' => $this->moodConfigurations['persönlich-emotional']['temperature'],
            ];
        }

        if ($rand <= 60) { // 10% (50 < rand <= 60): Emotional-sarkastisch
            return [
                'mood' => 'emotional-sarkastisch',
                'modifier' => $this->moodConfigurations['emotional-sarkastisch']['modifier'],
                'temperature' => $this->moodConfigurations['emotional-sarkastisch']['temperature'],
            ];
        }

        if ($rand <= 80) { // 20% (60 < rand <= 80): Politisch-informativ
            return [
                'mood' => 'politisch-informativ',
                'modifier' => $this->moodConfigurations['politisch-informativ']['modifier'],
                'temperature' => $this->moodConfigurations['politisch-informativ']['temperature'],
            ];
        }

        // 20% (rand > 80): Ausgewogen
        return [
            'mood' => 'ausgewogen',
            'modifier' => $this->moodConfigurations['ausgewogen']['modifier'],
            'temperature' => $this->moodConfigurations['ausgewogen']['temperature'],
        ];
    }

    /**
     * Returns a time reference based on the current time if the mood contains 'emotional'
     * and a random chance succeeds (20% chance). Returns an empty string otherwise.
     *
     * @param string $currentMood
     * @return string
     */
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

    /**
     * Determines the consistency threshold based on the previous mood and the current mood.
     * If the previous mood exists and differs from the current mood, a lower threshold is applied.
     *
     * @param string|null $previousMood
     * @param string $currentMood
     * @return int
     */
    public function getConsistencyThreshold(?string $previousMood, string $currentMood): int
    {
        return ($previousMood !== null && $previousMood !== $currentMood) ? 50 : 70;
    }

    /**
     * Retrieves the current mood configurations.
     *
     * @return array<string, array{modifier: string, temperature: float}>
     */
    public function getMoods(): array
    {
        return $this->moodConfigurations;
    }

    /**
     * Sets the mood configurations.
     *
     * @param array<string, array{modifier: string, temperature: float}> $moods
     */
    public function setMoods(array $moods): void
    {
        $this->moodConfigurations = $moods;
    }

    /**
     * Adds a new mood configuration.
     *
     * @param string $mood The mood key to add.
     * @param array{modifier: string, temperature: float} $configuration The configuration for the mood.
     */
    public function addMood(string $mood, array $configuration): void
    {
        if (!isset($this->moodConfigurations[$mood])) {
            $this->moodConfigurations[$mood] = $configuration;
        }
    }

    /**
     * Removes a mood configuration.
     *
     * @param string $mood The mood key to remove.
     */
    public function removeMood(string $mood): void
    {
        if (isset($this->moodConfigurations[$mood])) {
            unset($this->moodConfigurations[$mood]);
        }
    }
}
