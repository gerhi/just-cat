<?php
/**
 * Fixed texts shown to test-takers. The language is chosen per test in the
 * admin area. Edit freely; the item texts themselves come from the item bank.
 */

declare(strict_types=1);

defined('CAT') or exit;

function texts(string $lang): array
{
    $all = [
        'de' => [
            'start' => 'Test starten',
            'next' => 'Weiter',
            'progress' => 'Frage %d von höchstens %d',
            'choose' => 'Bitte wählen Sie eine Antwort aus.',
            'saved_note' => 'Ihre Antwort wird nach jeder Frage gespeichert.',
            'yes' => 'Ja',
            'no' => 'Nein',
            'done_title' => 'Vielen Dank!',
            'done_text' => 'Ihre Antworten wurden gespeichert. Sie können dieses Fenster jetzt schließen.',
            'done_continue' => 'Weiter',
            'done_continue_text' => 'Ihre Antworten wurden gespeichert. Bitte klicken Sie auf „Weiter“, um zur Befragung zurückzukehren.',
            'unavailable' => 'Dieser Test ist derzeit nicht verfügbar.',
            'not_found' => 'Dieser Test wurde nicht gefunden.',
            'need_ref' => 'Dieser Test kann nur über den Link aus der Befragung gestartet werden.',
            'invalid_ref' => 'Dieser Link ist ungültig. Bitte verwenden Sie den Link aus der Befragung.',
            'error' => 'Es ist ein Fehler aufgetreten. Bitte laden Sie die Seite neu.',
            'info_adaptive' => 'Die Fragen werden passend zu Ihren bisherigen Antworten ausgewählt. Daher kann die Anzahl der Fragen variieren.',
        ],
        'en' => [
            'start' => 'Start test',
            'next' => 'Next',
            'progress' => 'Question %d of at most %d',
            'choose' => 'Please select an answer.',
            'saved_note' => 'Your answer is saved after each question.',
            'yes' => 'Yes',
            'no' => 'No',
            'done_title' => 'Thank you!',
            'done_text' => 'Your answers have been saved. You can close this window now.',
            'done_continue' => 'Continue',
            'done_continue_text' => 'Your answers have been saved. Please click “Continue” to return to the survey.',
            'unavailable' => 'This test is currently not available.',
            'not_found' => 'This test was not found.',
            'need_ref' => 'This test can only be started from the link in the survey.',
            'invalid_ref' => 'This link is invalid. Please use the link from the survey.',
            'error' => 'Something went wrong. Please reload the page.',
            'info_adaptive' => 'Questions are selected based on your previous answers, so the number of questions can vary.',
        ],
        'fr' => [
            'start' => 'Commencer le test',
            'next' => 'Suivant',
            'progress' => 'Question %d sur %d au maximum',
            'choose' => 'Veuillez choisir une réponse.',
            'saved_note' => 'Votre réponse est enregistrée après chaque question.',
            'yes' => 'Oui',
            'no' => 'Non',
            'done_title' => 'Merci !',
            'done_text' => 'Vos réponses ont été enregistrées. Vous pouvez maintenant fermer cette fenêtre.',
            'done_continue' => 'Continuer',
            'done_continue_text' => 'Vos réponses ont été enregistrées. Veuillez cliquer sur « Continuer » pour revenir au questionnaire.',
            'unavailable' => 'Ce test n’est pas disponible pour le moment.',
            'not_found' => 'Ce test est introuvable.',
            'need_ref' => 'Ce test ne peut être lancé qu’à partir du lien figurant dans le questionnaire.',
            'invalid_ref' => 'Ce lien n’est pas valide. Veuillez utiliser le lien figurant dans le questionnaire.',
            'error' => 'Une erreur s’est produite. Veuillez recharger la page.',
            'info_adaptive' => 'Les questions sont choisies en fonction de vos réponses précédentes ; leur nombre peut donc varier.',
        ],
        'es' => [
            'start' => 'Comenzar la prueba',
            'next' => 'Siguiente',
            'progress' => 'Pregunta %d de %d como máximo',
            'choose' => 'Por favor, elija una respuesta.',
            'saved_note' => 'Su respuesta se guarda después de cada pregunta.',
            'yes' => 'Sí',
            'no' => 'No',
            'done_title' => '¡Muchas gracias!',
            'done_text' => 'Sus respuestas se han guardado. Ya puede cerrar esta ventana.',
            'done_continue' => 'Continuar',
            'done_continue_text' => 'Sus respuestas se han guardado. Haga clic en «Continuar» para volver a la encuesta.',
            'unavailable' => 'Esta prueba no está disponible en este momento.',
            'not_found' => 'No se ha encontrado esta prueba.',
            'need_ref' => 'Esta prueba solo se puede iniciar desde el enlace de la encuesta.',
            'invalid_ref' => 'Este enlace no es válido. Utilice el enlace de la encuesta.',
            'error' => 'Se ha producido un error. Vuelva a cargar la página.',
            'info_adaptive' => 'Las preguntas se seleccionan según sus respuestas anteriores, por lo que su número puede variar.',
        ],
        'ja' => [
            'start' => 'テストを開始',
            'next' => '次へ',
            'progress' => '質問 %d（最大 %d 問）',
            'choose' => '回答を選択してください。',
            'saved_note' => '回答は質問ごとに保存されます。',
            'yes' => 'はい',
            'no' => 'いいえ',
            'done_title' => 'ありがとうございました',
            'done_text' => '回答は保存されました。このウィンドウは閉じてかまいません。',
            'done_continue' => '次へ進む',
            'done_continue_text' => '回答は保存されました。「次へ進む」をクリックしてアンケートに戻ってください。',
            'unavailable' => 'このテストは現在ご利用いただけません。',
            'not_found' => 'このテストは見つかりません。',
            'need_ref' => 'このテストはアンケート内のリンクからのみ開始できます。',
            'invalid_ref' => 'このリンクは無効です。アンケート内のリンクを使用してください。',
            'error' => 'エラーが発生しました。ページを再読み込みしてください。',
            'info_adaptive' => '質問はこれまでの回答に応じて選ばれるため、質問数は人によって異なります。',
        ],
        'zh' => [
            'start' => '开始测试',
            'next' => '下一题',
            'progress' => '第 %d 题（最多 %d 题）',
            'choose' => '请选择一个答案。',
            'saved_note' => '每道题作答后都会自动保存。',
            'yes' => '是',
            'no' => '否',
            'done_title' => '谢谢！',
            'done_text' => '您的回答已保存。现在可以关闭此窗口。',
            'done_continue' => '继续',
            'done_continue_text' => '您的回答已保存。请点击“继续”返回问卷。',
            'unavailable' => '此测试当前不可用。',
            'not_found' => '未找到此测试。',
            'need_ref' => '此测试只能通过问卷中的链接开始。',
            'invalid_ref' => '此链接无效。请使用问卷中的链接。',
            'error' => '出现错误。请刷新页面。',
            'info_adaptive' => '题目会根据您之前的回答进行选择，因此题目数量可能有所不同。',
        ],
    ];
    return $all[$lang] ?? $all['en'];
}

/** Languages for participant pages and the admin interface (code => own name). */
function available_languages(): array
{
    return CAT_LANGS;
}
