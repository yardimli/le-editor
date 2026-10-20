<?php
return [
    'required' => ':attribute alanı zorunludur.', 'string' => ':attribute metin olmalıdır.',
    'email' => ':attribute geçerli bir e-posta adresi olmalıdır.', 'confirmed' => ':attribute doğrulaması eşleşmiyor.',
    'unique' => ':attribute zaten kullanılıyor.', 'in' => 'Seçilen :attribute geçersiz.',
    'integer' => ':attribute tam sayı olmalıdır.', 'boolean' => ':attribute doğru veya yanlış olmalıdır.',
    'file' => ':attribute bir dosya olmalıdır.', 'extensions' => ':attribute şu uzantılardan birine sahip olmalıdır: :values.',
    'max' => ['string' => ':attribute en fazla :max karakter olabilir.', 'numeric' => ':attribute en fazla :max olabilir.', 'file' => ':attribute en fazla :max kilobayt olabilir.'],
    'min' => ['string' => ':attribute en az :min karakter olmalıdır.', 'numeric' => ':attribute en az :min olmalıdır.'],
    'current_password' => 'Mevcut parola yanlış.', 'lowercase' => ':attribute küçük harf olmalıdır.',
    'attributes' => ['email' => 'E-posta', 'password' => 'Parola', 'name' => 'Ad', 'title' => 'Başlık', 'file' => 'Dosya',
        'text' => 'Metin', 'prompt' => 'Komut', 'model' => 'Model', 'chunk_size' => 'Parça boyutu', 'language' => 'Dil'],
];
