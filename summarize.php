<?php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/log.txt');
error_reporting(E_ALL);

function sanitizeText($text) {
    $sanitizedText = preg_replace("/[^a-zA-Z0-9\s.,!?()\"'\-]/", "", $text);
    return $sanitizedText;
}

function remove_special_char($str) {
    $res = preg_replace('/"/', '', $str);
    return $res;
}

function extractText($filePath, $originalFileName) {
    $apiKey = 'REPLACE_WITH_CONVERT_API_KEY';

    $fileExtension = pathinfo($originalFileName, PATHINFO_EXTENSION);

    if (!in_array($fileExtension, ['pdf', 'docx'])) {
        exit('Error: Only PDF and DOCX files are supported.');
    }

    $absoluteFilePath = realpath($filePath);

    if (!$absoluteFilePath || !file_exists($absoluteFilePath)) {
        exit('Error: Temporary file does not exist or cannot be accessed.');
    }

    $tempFilePath = $absoluteFilePath . '.' . $fileExtension;
    if (!@rename($absoluteFilePath, $tempFilePath)) {
        exit('Error: Unable to rename the uploaded file. Check file permissions.');
    }

    $convertFormat = $fileExtension === 'pdf' ? 'pdf' : 'docx';
    $api = "https://v2.convertapi.com/convert/$convertFormat/to/txt?auth=$apiKey";

    $fileData = new CURLFile($tempFilePath);
    $postData = ['File' => $fileData];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $errorMessage = curl_error($ch);
        curl_close($ch);
        unlink($tempFilePath);
        exit("cURL Error: $errorMessage");
    }

    curl_close($ch);

    unlink($tempFilePath);

    $responseData = json_decode($response, true);

    if (isset($responseData['Code'])) {
        exit("Error: ConvertAPI returned an error - " . $responseData['Message']);
    }

    if (isset($responseData['Files'][0]['FileData'])) {
        $base64Text = $responseData['Files'][0]['FileData'];
        $decodedText = base64_decode($base64Text);
        return remove_special_char($decodedText);
    } else {
        exit('Error: No extracted text found in the ConvertAPI response.');
    }
}

function summarizeText($text, $summaryLength) {
    $apiUrl = 'https://api-inference.huggingface.co/models/facebook/bart-large-cnn';
    $apiToken = 'REPLACE_WITH_HUGGINGFACE_API_KEY';
    $maxRetries = 3;
    $retryDelay = 2;

    $sanitizedText = sanitizeText($text);

    switch ($summaryLength) {
        case 'Short':
            $maxLength = 100;
            break;
        case 'Medium':
            $maxLength = 125;
            break;
        case 'Long':
            $maxLength = 150;
            break;
        default:
            $maxLength = 100;
            break;
    }

    $data = [
        'inputs' => $sanitizedText,
        'parameters' => [
            'max_length' => $maxLength + 20,
            'min_length' => $maxLength - 50,
            'length_penalty' => 3.0,
            'num_beams' => 4,
            'early_stopping' => true
        ]
    ];

    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer $apiToken",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];

    $attempt = 0;
    $response = null;
    while ($attempt < $maxRetries) {
        $context = stream_context_create($options);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiToken",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);

        if ($response !== false) {
            break;
        }

        if (curl_errno($ch)) {
            $errorCode = curl_errno($ch);
            $errorMessage = curl_error($ch);
            error_log("cURL error ($errorCode): $errorMessage", 3, __DIR__ . '/log.txt');
        }
        curl_close($ch);
        $attempt++;
        sleep($retryDelay * $attempt);
    }

    if ($response === false) {
        exit('Error: API request failed after multiple retries.');
    }

    $summary = json_decode($response, true);

    if (isset($summary['error'])) {
        error_log("Hugging Face API Error: " . $summary['error'], 3, __DIR__ . '/log.txt');
        exit('Error: ' . $summary['error']);
    }

    if (isset($summary[0]['summary_text'])) {
        return $summary[0]['summary_text'];
    } else {
        exit('Error: Summary not returned by Hugging Face.');
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_FILES['file']['tmp_name']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
        exit('Error: File upload failed. Please try again.');
    }
    
    $tmpFilePath = $_FILES['file']['tmp_name'];
    $originalFileName = $_FILES['file']['name'];

    if (!file_exists($tmpFilePath)) {
        exit('Error: Uploaded file does not exist.');
    }

    $text = extractText($tmpFilePath, $originalFileName);
    if ($text) {}

    $summaryLength = $_POST['length'];

    if (!empty($text)) {
        $summary = summarizeText($text, $summaryLength);
        echo $summary;
    } else {
        echo 'Error: No text extracted.';
    }
}
?>
