<?php

namespace Database\Seeders;

use App\Models\PromptTemplate;
use Illuminate\Database\Seeder;

class PromptSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            ['proofread', 'general', 'light', 'Son okuma', 'Proofreading',
                'Yalnızca yazım, dil bilgisi ve noktalama hatalarını düzelt. Yazarın sözcük seçimini, anlatımını ve biçimini koru.',
                'Correct only spelling, grammar and punctuation errors. Preserve vocabulary, voice and formatting.'],
            ['fiction-light', 'fiction', 'light', 'Kurgu · Nazik dokunuş', 'Fiction · Gentle polish',
                'Kurgu metnindeki aksayan cümleleri hafifçe düzelt. Anlatıcı sesini, zaman kipini, bakış açısını ve karakterlerin özelliklerini koru. Olay ekleme.',
                'Lightly smooth awkward fiction prose. Preserve narrator voice, tense, viewpoint and characterization. Add no events.'],
            ['fiction-flow', 'fiction', 'medium', 'Kurgu · Akış ve ritim', 'Fiction · Flow and rhythm',
                'Kurgu metninin akışını, cümle ritmini ve geçişlerini iyileştir. Gereksiz tekrarları azalt; olay örgüsünü, imgeleri ve yazarın üslubunu koru.',
                'Improve fiction flow, sentence rhythm and transitions. Reduce unnecessary repetition; preserve plot, imagery and authorial style.'],
            ['fiction-deep', 'fiction', 'deep', 'Kurgu · Kapsamlı satır düzenlemesi', 'Fiction · Deep line edit',
                'Kurgu metnini cümle düzeyinde kapsamlı biçimde düzenle. Anlatımı, ritmi ve açıklığı güçlendir. Anlamı, olayları ve karakter seslerini değiştirme; yeni içerik ekleme.',
                'Perform a substantial sentence-level fiction edit for expression, rhythm and clarity. Preserve meaning, events and character voices; add no new content.'],
            ['dialogue', 'fiction', 'medium', 'Diyalog · Doğal konuşma', 'Dialogue · Natural speech',
                'Diyalogları daha doğal ve okunabilir yap. Her karakterin sesini, ağız özelliklerini, niyetini ve alt metni koru. Anlatı bölümlerine yalnızca gerektiğinde dokun.',
                'Make dialogue more natural and readable. Preserve each character’s voice, dialect, intent and subtext. Change narration only when necessary.'],
            ['nonfiction-light', 'nonfiction', 'light', 'Kurgu dışı · Hassas düzeltme', 'Nonfiction · Careful copyedit',
                'Kurgu dışı metnin dil bilgisi ve anlatım hatalarını hafifçe düzelt. Tüm olguları, sayıları, alıntıları, kaynakları ve teknik terimleri aynen koru. Doğrulanmamış iddia ekleme.',
                'Lightly copyedit nonfiction for grammar and expression. Preserve facts, numbers, quotations, citations and technical terms. Add no unverified claims.'],
            ['nonfiction-clear', 'nonfiction', 'deep', 'Kurgu dışı · Açıklık ve özlülük', 'Nonfiction · Clarity and concision',
                'Kurgu dışı metindeki karmaşık cümleleri sadeleştir, tekrarları azalt ve mantıksal geçişleri iyileştir. Bilgiyi, kanıtları, çekinceleri ve kaynakları eksiksiz koru.',
                'Simplify complex nonfiction sentences, reduce repetition and improve logical transitions. Preserve all information, evidence, qualifications and citations.'],
            ['academic', 'nonfiction', 'medium', 'Akademik · Kesinlik ve tutarlılık', 'Academic · Precision and consistency',
                'Akademik tonu, terim tutarlılığını ve ifade kesinliğini iyileştir. İddiaların gücünü artırma; belirsizlikleri, atıfları ve araştırma sonuçlarını değiştirme.',
                'Improve academic tone, terminology consistency and precision. Do not strengthen claims or alter uncertainty, references or research results.'],
            ['poetry-light', 'poetry', 'light', 'Şiir · Biçimi koruyan düzeltme', 'Poetry · Form-preserving proofread',
                'Şiirde yalnızca açık yazım hatalarını düzelt. Bilinçli dil sapmalarını, satır sonlarını, kıtaları, noktalama tercihlerini, ölçüyü ve kafiyeyi koru. Şüphedeysen değiştirme.',
                'Correct only obvious typos in poetry. Preserve intentional deviations, line breaks, stanzas, punctuation choices, meter and rhyme. When unsure, leave unchanged.'],
            ['poetry-polish', 'poetry', 'medium', 'Şiir · İmge ve ses', 'Poetry · Imagery and sound',
                'Şiirin imgelerini ve ses akışını ölçülü biçimde incelt. Anlamı, duyguyu, dize ve kıta yapısını, ölçüyü ve kafiyeyi koru. Şiiri düzyazıya çevirme.',
                'Carefully refine poetic imagery and sound. Preserve meaning, emotion, line and stanza structure, meter and rhyme. Never turn poetry into prose.'],
        ];
        foreach ($presets as [$slug, $genre, $intensity, $tr, $en, $trPrompt, $enPrompt]) {
            PromptTemplate::updateOrCreate(['slug' => $slug], ['user_id' => null, 'genre' => $genre, 'intensity' => $intensity,
                'names' => compact('tr', 'en'), 'instructions' => ['tr' => $trPrompt, 'en' => $enPrompt]]);
        }
    }
}
