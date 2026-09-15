<?php

return [
    'accepted' => 'The :attribute field must be accepted.',
    'array' => 'The :attribute field must be an array.',
    'file' => 'The :attribute field must be a file.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'max' => [
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'numeric' => 'The :attribute field must be a number.',
    'required' => 'The :attribute field is required.',
    'size' => [
        'file' => 'The :attribute field must be :size kilobytes.',
    ],
    'uploaded' => 'The :attribute failed to upload. The file may exceed upload limits.',

    'custom' => [
        'upload' => [
            'max' => 'The file must not exceed :max kilobytes.',
            'uploaded' => 'The file failed to upload or exceeds server upload limits.',
        ],
        'uploads.*' => [
            'max' => 'Each file must not exceed :max kilobytes.',
            'uploaded' => 'A file failed to upload or exceeds server upload limits.',
        ],
    ],

    'attributes' => [
        'upload' => 'file',
        'uploads' => 'files',
        'uploads.*' => 'file',
        'file' => 'file',
    ],
];
