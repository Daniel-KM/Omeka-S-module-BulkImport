(function ($) {
    $(document).ready(function() {

        const basePath = window.location.pathname.replace(/\/admin\/.*/, '');

        // Adapted from https://developer.mozilla.org/fr/docs/Web/HTML/Element/Input/file (licence cc0/public domain).
        // Add the listener on existing and new media files upload buttons.
        // The new listener is set above to manage dynamically created medias.
        $('#item-media').on('change', '#media-list .media-files-input', function (e) {
            e.preventDefault();
            updateImageDisplay($(this));
        });

        function updateImageDisplay(inputUpload) {
            const input = inputUpload[0];
            const preview = inputUpload.closest('.media-field-wrapper').find('.media-files-input-preview')[0];

            while (preview.firstChild) {
                preview.removeChild(preview.firstChild);
            }

            const curFiles = input.files;
            if (curFiles.length === 0) {
                const para = document.createElement('p');
                para.textContent = inputUpload.data('translate-no-file');
                preview.appendChild(para);
            } else {
                // Is vanilla js really simpler here?
                const allowedMediaTypes = inputUpload.data('allowed-media-types').split(',');
                const allowedExtensions = inputUpload.data('allowed-extensions').split(',');
                const maxSizeFile = parseInt(inputUpload.data('max-size-file'));
                const maxSizePost = parseInt(inputUpload.data('max-size-post'));
                const maxFileUploads = parseInt(inputUpload.data('max-file-uploads'));
                const list = document.createElement('ol');
                var total = 0;
                preview.appendChild(list);
                for (const file of curFiles) {
                    total += file.size;
                    const listItem = document.createElement('li');
                    const div = document.createElement('div');
                    div.classList.add('media-info');
                    if (validateFile(file, allowedMediaTypes, allowedExtensions, maxSizeFile)) {
                        const divImage = document.createElement('div');
                        divImage.classList.add('resource-thumbnail');
                        const image = document.createElement('img');
                        const mainType = file.type.split('/')[0];
                        const subType = file.type.split('/')[1];
                        image.src = file.size < 20000000
                            && mainType === 'image' && ['avif', 'apng', 'bmp', 'gif', 'ico', 'jpeg', 'png', 'svg', 'webp'].includes(subType)
                            ? URL.createObjectURL(file)
                            : defaultThumbnailUrl(file);
                        divImage.appendChild(image);
                        div.appendChild(divImage);
                        const span = document.createElement('span');
                        span.classList.add('resource-name');
                        span.textContent = `${file.name} (${returnFileSize(file.size)})`;
                        div.appendChild(span);
                        listItem.appendChild(div);
                    } else {
                        const pError = document.createElement('p');
                        pError.textContent = file.name + ': ' + inputUpload.data('translate-invalid-file');
                        pError.classList.add('error');
                        div.appendChild(pError);
                        div.classList.add('messages');
                        listItem.appendChild(div);
                    }
                    list.appendChild(listItem);
                }
                if (total > maxSizePost) {
                    const div = document.createElement('div');
                    const pError = document.createElement('p');
                    pError.textContent = inputUpload.data('translate-max-size-post');
                    pError.classList.add('error');
                    div.appendChild(pError);
                    div.classList.add('messages');
                    preview.prepend(div);
                }
                if (curFiles.length > maxFileUploads) {
                    const div = document.createElement('div');
                    const pError = document.createElement('p');
                    pError.textContent = inputUpload.data('translate-max-file-uploads');
                    pError.classList.add('error');
                    div.appendChild(pError);
                    div.classList.add('messages');
                    preview.prepend(div);
                }
            }
        }

        function validateFile(file, allowedMediaTypes, allowedExtensions, maxSizeFile) {
            const extension = file.name.slice((file.name.lastIndexOf('.') - 1 >>> 0) + 2);
            const isForbidden = (allowedMediaTypes.length && !allowedMediaTypes.includes(file.type))
                || (allowedExtensions.length && !allowedExtensions.includes(extension))
                || file.name.substr(0, 1) === '.'
                || file.size === 0
                || file.size > maxSizeFile;
            return !isForbidden;
        }

        function returnFileSize(number) {
            if (number < 1000) {
                return number + ' bytes';
            } else if (number > 1000 && number < 1000000) {
                return (number / 1000).toFixed(0) + ' KB';
            } else if (number > 1000000) {
                return (number / 1000000).toFixed(0) + ' MB';
            }
        }

        function defaultThumbnailUrl(file) {
            const mainType = file.type.split('/')[0];
            return basePath
                + '/application/asset/thumbnails/'
                + (['audio', 'image', 'video'].includes(mainType) ? mainType : 'default')
                + '.png';
        }

    });
})(jQuery);
