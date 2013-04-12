<?php

namespace Infinite\FormBundle\Form\DataTransformer;

use Doctrine\Common\Persistence\ObjectManager;
use Infinite\FormBundle\Attachment\PathHelper;
use Infinite\FormBundle\Model\AttachmentInterface;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AttachmentTransformer implements DataTransformerInterface
{
    protected $dataClass;
    protected $om;
    protected $pathHelper;
    protected $secret;

    public function __construct($options, ObjectManager $om, PathHelper $pathHelper)
    {
        $this->dataClass = $options['class'];
        $this->om = $om;
        $this->pathHelper = $pathHelper;
        $this->secret = $options['secret'];
    }

    public function transform($value)
    {
        $meta = null;

        /** @var AttachmentInterface $value */
        if ($value !== null) {
            // Serialise to preserve the data, and use a message authentication code to verify that the
            // server generated it. We need all the data even if the attachment already exists, because
            // it might have been modified. (ObjectManager doesn't provide a hasChanged method.)
            $savedData = base64_encode(serialize($value));
            $mac = hash_hmac('sha1', $savedData, $this->secret);
            $meta = $mac . '|' . $savedData;
        }

        $more = $value ? $value->getAdditionalFormData() : array();

        return array(
            'file' => null,
            'removed' => false,
            'meta' => $meta,
        ) + $more;
    }

    public function reverseTransform($value)
    {
        if (!is_array($value) || !array_key_exists('meta', $value)) {
            throw new TransformationFailedException('Error in form submission');
        }

        /** @var UploadedFile $file */
        $file = isset($value['file']) ? $value['file'] : null;
        $meta = $value['meta'];
        $removed = !empty($value['removed']);

        if ($meta === '') {
            $meta = null;
        }

        /** @var AttachmentInterface $data */
        $data = null;

        if ($file === null && ($removed || $meta === null)) {
            // No new file uploaded, and either 'remove' was clicked or there was no attachment in the first place.
            // Just need to return null, so nothing to do here.
        } else {
            // Otherwise, create/update/preserve an attachment.

            if ($meta !== null) {
                // Verify the message authentication code and deserialise the saved object.
                list($mac, $savedData) = explode('|', $meta, 2);

                if (hash_hmac('sha1', $savedData, $this->secret) === $mac) {
                    $data = unserialize(base64_decode($savedData));

                    if ($data->getId()) {
                        // Don't use merge() here - we only want to update the specific fields saved in $meta.
                        $postedData = $data;
                        $data = $this->om->find($this->dataClass, $postedData->getId());

                        $data->setFilename($postedData->getFilename());
                        $data->setFileHash($postedData->getFileHash());
                        $data->setFileSize($postedData->getFileSize());
                        $data->setMimeType($postedData->getMimeType());
                        $data->setPhysicalName($postedData->getPhysicalName());
                    }
                }
            }

            if ($file !== null) {
                // File posted - create or update a record
                $data = $data ?: new $this->dataClass;

                // Accept the upload
                $this->pathHelper->acceptUpload($file, $data);
            }

            // Apply any other posted fields
            $data->setAdditionalFormData($value);
        }

        return $data;
    }
}
