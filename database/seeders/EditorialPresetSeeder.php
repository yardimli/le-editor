<?php
namespace Database\Seeders;

use App\Models\PromptTemplate;
use Illuminate\Database\Seeder;

class EditorialPresetSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['grammar', 'light', 'Dil bilgisi ve yazımı düzelt', 'Fix grammar & spelling',
                'Yazım, dil bilgisi ve noktalama hatalarını düzelt; karakterlerin bilinçli konuşma özelliklerini ve anlatıcı sesini koru.',
                'Correct spelling, grammar and punctuation while preserving intentional character speech and narrator voice.',
                'Yazım, dil bilgisi ve noktalama hatalarını düzelt; teknik terimleri, alıntıları ve kaynak gösterimini koru.',
                'Correct spelling, grammar and punctuation while preserving technical terms, quotations and citation conventions.'],
            ['vocabulary', 'medium', 'Sözcük seçimini zenginleştir', 'Enhance vocabulary',
                'Bağlama uygun, canlı ve kesin sözcükler seç; karakterlerin dil düzeyini, dönemi ve anlatıcının üslubunu koru. Süslü dili gereksiz yere artırma.',
                'Choose vivid, precise words appropriate to the scene, period and narrator. Preserve character register and avoid gratuitous ornate language.',
                'Belirsiz sözcükleri daha kesin karşılıklarla değiştir; yerleşik teknik terimleri koru, gereksiz jargon ekleme.',
                'Replace vague wording with precise vocabulary. Retain established technical terms and avoid unnecessary jargon.'],
            ['flow', 'medium', 'Cümle akışını iyileştir', 'Improve sentence flow',
                'Cümle ritmini ve sahneler içindeki geçişleri iyileştir; anlatının temposunu, bakış açısını ve zaman kipini koru.',
                'Improve sentence rhythm and transitions within scenes while preserving narrative pacing, viewpoint and tense.',
                'Cümleler arasındaki mantıksal geçişleri ve okunabilirliği iyileştir; argüman sırasını ve neden-sonuç ilişkilerini koru.',
                'Improve sentence flow and logical transitions while preserving the order of arguments and causal relationships.'],
            ['clarity', 'medium', 'Anlatımı netleştir', 'Improve clarity',
                'Karmaşık anlatımı ve istemsiz belirsizlikleri gider; kasıtlı gizemi, alt metni ve olay örgüsünü koru.',
                'Clarify confusing prose and unintended ambiguity while preserving deliberate mystery, subtext and plot.',
                'Karmaşık cümleleri anlaşılır kıl; kapsamı, çekinceleri, kanıtları ve iddiaların kesinlik düzeyini değiştirme.',
                'Clarify complex sentences without changing scope, qualifications, evidence or the strength of claims.'],
            ['formal', 'medium', 'Daha resmî yap', 'Make more formal',
                'Anlatım dilini ölçülü biçimde resmileştir; diyaloglardaki karakter seslerini ve eserin dönemini koru.',
                'Make narration more formal while retaining character voices in dialogue and the setting’s period.',
                'Profesyonel ve resmî bir anlatım kullan; anlamı ve erişilebilirliği koru, iddiaları güçlendirme.',
                'Use a professional, formal register while preserving meaning and accessibility without strengthening claims.'],
            ['casual', 'medium', 'Daha samimi yap', 'Make more casual',
                'Anlatımı daha rahat ve doğal yap; anlatıcı kişiliğini ve her karakterin konuşma biçimini koru.',
                'Make prose more relaxed and natural while preserving narrator personality and each character’s speech patterns.',
                'Metni daha samimi ve konuşma diline yakın yap; teknik doğruluğu, kaynakları ve önemli ayrıntıları koru.',
                'Make the text more conversational and approachable while retaining technical accuracy, references and important details.'],
            ['emotion', 'medium', 'Duygusal etkiyi güçlendir', 'Enhance emotional impact',
                'Mevcut duygu ve alt metnin etkisini sözcük seçimi ve ritimle güçlendir; yeni duygu, olay veya karakter motivasyonu uydurma.',
                'Strengthen existing emotion and subtext through diction and rhythm. Invent no feelings, events or character motivations.',
                'Mevcut insani önemi ve anlatılan deneyimlerin etkisini daha açık ifade et; kanıt, alıntı veya yaşanmış deneyim uydurma, sansasyon yaratma.',
                'Clarify the existing human significance and impact of described experiences. Do not invent evidence, quotations or experiences, or sensationalize claims.'],
            ['poetic', 'deep', 'Daha şiirsel yap', 'Make more poetic',
                'Mevcut imgeleri ve dilin müzikalitesini güçlendir; anlamı, olayları, anlatıcı sesini ve varsa dize yapısını koru.',
                'Refine existing imagery and musicality while preserving meaning, events, narrator voice and any verse structure.',
                'Anlatıma ölçülü ritim ve zarafet kat; olguları mecaza dönüştürme, teknik kesinliği azaltma veya yeni benzetmelerle iddia ekleme.',
                'Add restrained rhythm and elegance without turning facts into metaphor, reducing technical precision or introducing claims through new analogies.'],
            ['italics', 'light', 'Önemli sözcükleri italik yap', 'Italicize important words',
                'Yalnızca bağlamın gerektirdiği az sayıda önemli sözcüğü italikle vurgula; diyalogdaki vurguyu ve anlatıcı niyetini değiştirme.',
                'Italicize a few contextually important words sparingly, without changing dialogue emphasis or narrator intent.',
                'Gerekli anahtar kavramları ölçülü biçimde italikle vurgula; alıntıları değiştirme ve iddiaları olduğundan güçlü gösterme.',
                'Italicize essential key concepts sparingly, without altering quotations or overstating claims.'],
        ];
        foreach ($items as [$key, $intensity, $tr, $en, $fictionTr, $fictionEn, $nonfictionTr, $nonfictionEn]) {
            foreach (['fiction', 'nonfiction'] as $genre) {
                PromptTemplate::updateOrCreate(['slug' => "editorial-{$genre}-{$key}"], [
                    'user_id' => null, 'genre' => $genre, 'intensity' => $intensity,
                    'names' => compact('tr', 'en'),
                    'instructions' => ['tr' => $genre === 'fiction' ? $fictionTr : $nonfictionTr,
                        'en' => $genre === 'fiction' ? $fictionEn : $nonfictionEn],
                ]);
            }
        }
    }
}
