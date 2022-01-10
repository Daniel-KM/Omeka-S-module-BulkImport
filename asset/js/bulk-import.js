(function ($) {
    $(document).ready(function() {

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
                para.textContent = 'No files currently selected for upload';
                preview.appendChild(para);
            } else {
                // Is vanilla js really simpler here?
                const list = document.createElement('ol');
                preview.appendChild(list);
                for (const file of curFiles) {
                    const listItem = document.createElement('li');
                    const div = document.createElement('div');
                    div.classList.add('media-info');
                    const divImage = document.createElement('div');
                    divImage.classList.add('resource-thumbnail');
                    const image = document.createElement('img');
                    image.src = URL.createObjectURL(file);
                    divImage.appendChild(image);
                    div.appendChild(divImage);
                    const span = document.createElement('span');
                    span.classList.add('resource-name');
                    span.textContent = `${file.name} (${returnFileSize(file.size)})`;
                    div.appendChild(span);
                    listItem.appendChild(div);
                    list.appendChild(listItem);
                }
            }
        }

        function returnFileSize(number) {
            if (number < 1000) {
                return number + ' bytes';
            } else if (number > 1000 && number < 1000000) {
                return (number / 1000).toFixed(1) + ' KB';
            } else if (number > 1000000) {
                return (number / 1000000).toFixed(1) + ' MB';
            }
        }

    });
})(jQuery);
