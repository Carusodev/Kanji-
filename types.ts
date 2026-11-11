export interface KanjiCard {
    kanji: string;
    pinyin: string;
    onyomi: string;
    kunyomi: string;
    meaning: string;
    hint: string;
    title: string;
}

export interface StudyProgress {
    points: number;
    reviewKanji: string[];
    unlockedCount: number;
    showItalian: boolean;
    updatedAt: string;
    quizHistory: QuizResult[];
}

export interface QuizResult {
    id: string;
    playedAt: string;
    correct: number;
    total: number;
    pointsEarned: number;
    unlockedKanji: string | null;
    quizType: "kanji" | "katakana" | "hiragana";
}

export type StudyMode = "home" | "cards" | "learn" | "quiz" | "vocabulary" | "katakana" | "hiragana";
